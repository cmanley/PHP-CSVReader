<?php
/**
* Contains the CSVReader, CSVReaderRow, and CSVReaderException classes.
*
* Dependencies:
* <pre>
* PHP 5.3 or higher
* </pre>
*
* TODO: Treat all streams as non-seekable to reduce code complexity.
* TODO: Handle transcoding errors so that they are more informative with line number etc.
*
* @author    Craig Manley
* @copyright Copyright © 2010, Craig Manley (www.craigmanley.com)
* @license   http://www.opensource.org/licenses/mit-license.php Licensed under MIT
* @version   $Id: CSVReader.php,v 1.14 2014/01/24 11:29:56 cmanley Exp $
* @package   cmanley
*/



/**
* Custom Exception class.
*
* @package  cmanley
*/
class CSVReaderException extends Exception {}



/**
* CSV file/stream reader class that implements the Iterator interface.
* It supports files having a (UTF-8 or UTF-*) BOM, as well as non-seekable streams.
*
* Example(s):
* <pre>
*	ini_set('auto_detect_line_separators', 1); // Only necessary if CSVReader is having trouble detecting line separators in MAC files.
*
*	// read a plain CSV file
*	$reader = new CSVReader('products.csv');
*
*	// read a gzipped CSV file
*	$reader = new CSVReader('compress.zlib://products.csv.gz');
*
*	// read CSV from STDIN
*	$reader = new CSVReader('php://stdin');
*
*	// read products.csv from within export.zip archive
*	$reader = new CSVReader('zip://export.zip#products.csv');
*
*	// read from a open file handle
*	$h = fopen('products.csv', 'r');
*	$reader = new CSVReader($h);
*
*	// Show fieldnames from 1st row:
*	print_r($reader->fieldNames());
*
*	// Iterate over all the rows. CVSReader behaves as an array.
*	foreach($reader as $row) { // $row is a CSVReaderRow object
*		print 'Price: ' . $row->get('Price') . "\n";
*		print 'Name: ' . $row['Name'] . "\n"; // $row also supports array access
*		print_r($row->toArray());
*		// TIP: Use PHP-Validate, by your's truly, to validate $row->toArray()
*	}
* </pre>
*
* @package  cmanley
*/
class CSVReader implements Iterator {

	protected $h; // File handle.
	protected $own_h; // Does this class own the file handle.
	protected $seekable; // Is the stream seekable?
	protected $initial_lines = array(); // For non-seekable streams: pre-read lines for inspecting BOM and encoding.
	protected $initial_lines_index = 0; // For non-seekable streams: index of which pre-read lines to read next. Used by _fgetcsv().
	protected $bom_len = 0; // Contains the BOM length.
	protected $field_cols = array(); // Associative array of fieldname => column index pairs.
	protected $row; // Current CSVReaderRow object.
	protected $key = -1; // Data row index.
	protected $must_transcode = false; // true if file encoding does not match internal encoding.

	// Options:
	protected $debug = false;
	protected $file_encoding = null;
	protected $internal_encoding = null;
	protected $skip_empty_lines = false;
	protected $length = 4096;
	protected $delimiter = null; //',';
	protected $enclosure = null; //'"';
	protected $escape = '\\';
	protected $line_separator; // For UTF-16LE it's multibyte, e.g. 0x0A00

	/**
	* Constructor.
	*
	* @param string|resource $file file name or handle opened for reading.
	* @param array $option optional associative array of any of these options:
	*	- debug: boolean, if true, then debug messages are emitted using error_log().
	*	- field_aliases: associative array of case insensitive field name alias (in file) => real name (as expected in code) pairs.
	*	- field_normalizer: optional callback that receives a field name by reference to normalize (e.g. make lowercase).
	*	- include_fields: optional array of field names to include. If given, then all other field names are excluded.
	*	- file_encoding, default null, which means guess encoding using BOM or mb_detect_encoding().
	*	- internal_encoding, default is mb_internal_encoding(), only effective if 'file_encoding' is given or detected.
	*	- length: string, default 4096, see stream_get_line()
	*	- delimiter: string, guessed if not given with default ',', see str_getcsv()
	*	- enclosure: string, guessed if not given with default '"', see str_getcsv()
	*	- escape: string, default backslash, see str_getcsv()
	*	- line_separator: string, if not given, then it's guessed.
	*	- skip_empty_lines, default false
	* @throws CSVReaderException
	* @throws \InvalidArgumentException
	*/
	function __construct($file, array $options = null) {
		if (is_string($file)) {
			if (($this->h = fopen($file, 'r')) === FALSE) {
				throw new CSVReaderException('Failed to open "' . $file . '" for reading');
			}
			$this->own_h = true;
		}
		elseif (is_resource($file)) {
			$this->h = $file;
			$this->own_h = false;
		}
		else {
			throw new \InvalidArgumentException(gettype($file) . ' is not a legal file argument type');
		}
		if (1) {
			$meta = stream_get_meta_data($this->h);
			$this->seekable = $meta['seekable'];
			unset($meta);
		}
		if (!is_array($options)) {
			$options = array();
		}

		// Get the options.
		$opt_field_aliases = null;
		$opt_field_normalizer = null;
		$opt_include_fields = null;
		if ($options) {
			foreach ($options as $key => $value) {
				if (in_array($key, array('debug', 'skip_empty_lines'))) {
					if (!(is_null($value) || is_bool($value) || is_int($value))) {
						throw new \InvalidArgumentException("The '$key' option must be a boolean");
					}
					$this->$key = $value;
				}
				elseif (in_array($key, array('enclosure', 'escape', 'line_separator'))) {
					if (!is_string($value)) {
						throw new \InvalidArgumentException("The '$key' option must be a string");
					}
					$this->$key = $value;
				}
				elseif (in_array($key, array('delimiter', 'file_encoding', 'internal_encoding'))) {
					if (!(is_string($value) && strlen($value))) {
						throw new \InvalidArgumentException("The '$key' option must be a non-empty string");
					}
					$this->$key = $value;
				}
				elseif ($key == 'length') {
					if (!(is_int($value) && ($value > 0))) {
						throw new \InvalidArgumentException("The '$key' option must be positive int");
					}
					$this->$key = $value;
				}
				elseif ($key == 'include_fields') {
					if (!is_array($value)) {
						throw new \InvalidArgumentException("The '$key' option must be an array");
					}
					$opt_include_fields = $value;
				}
				elseif ($key == 'field_aliases') {
					if (!is_array($value)) {
						throw new \InvalidArgumentException("The '$key' option must be an associative array");
					}
					$opt_field_aliases = $value;
				}
				elseif ($key == 'field_normalizer') {
					if (!is_callable($value)) {
						throw new \InvalidArgumentException("The '$key' option must be callable, such as a closure or a function name");
					}
					$opt_field_normalizer = $value;
				}
				else {
					throw new \InvalidArgumentException("Unknown option '$key'");
				}
			}
		}
		if (!$this->internal_encoding) {
			$this->internal_encoding = mb_internal_encoding();
		}
		$this->debug && error_log(__METHOD__ . ' Internal encoding: ' . $this->internal_encoding);
		$this->debug && error_log(__METHOD__ . ' File: ' . (is_string($file) ? $file : gettype($file)));
		$this->debug && error_log(__METHOD__ . ' Stream is seekable: ' . var_export($this->seekable,1));

		// Read the BOM, if any.
		if (1) {
			$line = fread($this->h, 4); // incomplete line!
			if ($line === false) {
				throw new CSVReaderException('No data found in CSV stream');
			}
			if ($first4 = substr($line,0,4)) {
				$first3 = substr($first4,0,3);
				$first2 = substr($first3,0,2);
				if ($first3 == chr(0xEF) . chr(0xBB) . chr(0xBF)) {
					$this->file_encoding = 'UTF-8';
					$this->bom_len = 3;
				}
				elseif ($first4 == chr(0x00) . chr(0x00) . chr(0xFE) . chr(0xFF)) {
					$this->file_encoding = 'UTF-32BE';
					$this->bom_len = 4;
				}
				elseif ($first4 == chr(0xFF) . chr(0xFE) . chr(0x00) . chr(0x00)) {
					$this->file_encoding = 'UTF-32LE';
					$this->bom_len = 4;
				}
				elseif ($first2 == chr(0xFE) . chr(0xFF)) {
					$this->file_encoding = 'UTF-16BE';
					$this->bom_len = 2;
				}
				elseif ($first2 == chr(0xFF) . chr(0xFE)) {
					$this->file_encoding = 'UTF-16LE';
					$this->bom_len = 2;
				}
			}
			if ($this->seekable) {
				if (fseek($this->h, $this->bom_len) != 0) {
					throw new \Exception('Failed to fseek to ' . $this->bom_len);
				}
			}
			else {
				if (!$this->line_separator && $this->bom_len && ($this->file_encoding != 'UTF-8')) { // A string with multibyte line separators. Can't use fgets() here because it doesn't support multibyte line separators.
					if ($this->file_encoding = 'UTF-16LE') {
						$this->line_separator = "\x0A\x00";
						$line .= stream_get_line($this->h, $this->length, $this->line_separator);
						if (substr($line, -2) == "\x0D\x00") {
							$this->line_separator = "\x0D\x00" . $this->line_separator;
							$line = substr($line, 0, strlen($line) - 2);
						}
					}
					elseif ($this->file_encoding = 'UTF-16BE') {
						$this->line_separator = "\x00\x0A";
						$line .= stream_get_line($this->h, $this->length, $this->line_separator);
						if (substr($line, -2) == "\x00\x0D") {
							$this->line_separator = "\x00\x0D" . $this->line_separator;
							$line = substr($line, 0, strlen($line) - 2);
						}
					}
					elseif ($this->file_encoding = 'UTF-32LE') {
						$this->line_separator = "\x0A\x00\x00\x00";
						$line .= stream_get_line($this->h, $this->length, $this->line_separator);
						if (substr($line, -4) == "\x0D\x00\x00\x00") {
							$this->line_separator = "\x0D\x00\x00\x00" . $this->line_separator;
							$line = substr($line, 0, strlen($line) - 4);
						}
					}
					elseif ($this->file_encoding = 'UTF-32BE') {
						$this->line_separator = "\x00\x00\x00\x0A";
						$line .= stream_get_line($this->h, $this->length, $this->line_separator);
						if (substr($line, -4) == "\x00\x00\x00\x0D") {
							$this->line_separator = "\x00\x00\x00\x0D" . $this->line_separator;
							$line = substr($line, 0, strlen($line) - 4);
						}
					}
					else {
						throw new Exception('Line ending detection for file encoding ' . $this->file_encoding . ' not implemented yet.');
					}
				}
				else {
					$line .= stream_get_line($this->h, $this->length);
				}
				$this->initial_lines []= $this->bom_len ? substr($line, $this->bom_len) : $line;
			}
			unset($line, $first4, $first3, $first2);
			if ($this->debug) {
				if ($this->bom_len) {
					error_log(__METHOD__ . ' BOM length: ' . $this->bom_len);
					error_log(__METHOD__ . ' BOM file encoding: ' . $this->file_encoding);
					error_log(__METHOD__ . ' Guessed line separator: ' . ' (0x' . bin2hex($this->line_separator) . ')');
				}
				else {
					error_log(__METHOD__ . ' File has no BOM.');
				}
			}
		}

		// Guess some options if necessary by sniffing a chunk of data from the file.
		if (is_null($this->delimiter) || is_null($this->enclosure) || !$this->file_encoding || !$this->line_separator) {
			// Read some (more) lines from the input to use for inspection.
			$s = null;
			if ($this->seekable) {
				$s = fread($this->h, 16384);
				if (fseek($this->h, $this->bom_len) != 0) {
					throw new \Exception('Failed to fseek to ' . $this->bom_len);
				}
			}
			else {
				$i = 0;
				while (($line = stream_get_line($this->h, $this->length, $this->line_separator)) !== false) {
					$this->initial_lines []= $line;
					if (++$i > 100) {
						break;
					}
				}
				$s = join($this->line_separator ? $this->line_separator : "\n", $this->initial_lines);
			}
			// If delimiter or enclosure are not given, try to guess them.
			if (is_null($this->delimiter) || is_null($this->enclosure)) {
				$guess = static::csv_guess($s, $this->file_encoding);
				if (is_null($this->delimiter)) {
					if (is_string($guess['delimiter'])) {
						$this->delimiter = $guess['delimiter'];
						$this->debug && error_log(__METHOD__ . ' Guessed delimiter: ' . $this->delimiter . ' (0x' . bin2hex($this->delimiter) . ')');
					}
					else {
						$this->delimiter = ',';
						$this->debug && error_log(__METHOD__ . ' Default delimiter: ' . $this->delimiter . ' (0x' . bin2hex($this->delimiter) . ')');
					}
				}
				if (is_null($this->enclosure)) {
					if (is_string($guess['enclosure'])) {
						$this->enclosure = $guess['enclosure'];
						$this->debug && error_log(__METHOD__ . ' Guessed enclosure: ' . $this->enclosure . ' (' . (strlen($this->enclosure) ? '0x' . bin2hex($this->enclosure) : 'none') . ')');
					}
					else {
						$this->enclosure = '"';
						$this->debug && error_log(__METHOD__ . ' Default enclosure: ' . $this->enclosure . ' (' . (strlen($this->enclosure) ? '0x' . bin2hex($this->enclosure) : 'none') . ')');
					}
				}
			}

			// Guess file encoding if unknown.
			if (!$this->file_encoding) {
				$encodings = array_unique(array_merge(
					$this->internal_encoding ? array($this->internal_encoding) : array(),
					mb_detect_order(),
					array('UTF-32BE', 'UTF-32LE', 'UTF-16BE', 'UTF-16LE', 'UTF-8', 'Windows-1252', 'cp1252', 'ISO-8859-1') // common file encodings
				));
				$this->debug && error_log(__METHOD__ . ' Guessing file encoding using encodings: ' . join(', ', $encodings));
				$this->file_encoding = mb_detect_encoding($s, $encodings, true);
				unset($s, $encodings);
				$this->debug && error_log(__METHOD__ . ' Guessed line separator: ' . ' (0x' . bin2hex($this->line_separator) . ')');
			}

			// Guess line separator.
			if (!$this->line_separator) {
				if (preg_match('/^UTF-(?:16|32)/', $this->file_encoding)) { // Multibyte line separators. Can't use fgets() here because it doesn't support multibyte line separators.
					if ($this->file_encoding = 'UTF-16LE') {
						$this->line_separator = "\x0A\x00";
					}
					elseif ($this->file_encoding = 'UTF-16BE') {
						$this->line_separator = "\x00\x0A";
					}
					elseif ($this->file_encoding = 'UTF-32LE') {
						$this->line_separator = "\x0A\x00\x00\x00";
					}
					elseif ($this->file_encoding = 'UTF-32BE') {
						$this->line_separator = "\x00\x00\x00\x0A";
					}
					else {
						throw new Exception('Line ending detection for file encoding ' . $this->file_encoding . ' not implemented yet.');
					}
				}
				else {
					$this->line_separator = "\n";
				}
			}
			$this->debug && error_log(__METHOD__ . ' Guessed line separator: ' . bin2hex($this->line_separator));
		}

		// Determine if transcoding is necessary for _fgetcsv().
		if ($this->file_encoding && $this->internal_encoding && strcasecmp($this->file_encoding, $this->internal_encoding)) {
			$this->must_transcode = true;
			// Try to gain effeciency here by eliminating transcoding if file encoding is a subset or alias of internal encoding.
			if ($this->file_encoding == 'ASCII') {
				if (in_array($this->internal_encoding, array('UTF-8', 'Windows-1252', 'cp1252', 'ISO-8859-1'))) {
					$this->must_transcode = false;
				}
			}
			elseif ($this->file_encoding == 'ISO-8859-1') {
				if (in_array($this->internal_encoding, array('Windows-1252', 'cp1252'))) {
					$this->must_transcode = false;
				}
			}
			elseif ($this->file_encoding == 'Windows-1252') {
				if ($this->internal_encoding == 'cp1252') { // alias
					$this->must_transcode = false;
				}
			}
			elseif ($this->file_encoding == 'cp1252') {
				if ($this->internal_encoding == 'Windows-1252') { // alias
					$this->must_transcode = false;
				}
			}
		}
		$this->debug && error_log(__METHOD__ . ' Must transcode: ' . var_export($this->must_transcode, 1));

		// Read header row.
		//@trigger_error('');
		if ($row = $this->_fgetcsv()) {
			$this->debug && error_log(__METHOD__ . ' Raw header row: ' . print_r($row,1));
			$unknown_fieldinfo = array(); # field name => col index pairs

			// Get the fieldname => column indices
			$x = 0;
			for ($x = 0; $x < count($row); $x++) {
				$name = trim($row[$x]);
				if (!(is_string($name) && strlen($name))) {
					continue;
				}
				if ($opt_field_normalizer) {
					call_user_func_array($opt_field_normalizer, array(&$name));
					if (!(is_string($name) && strlen($name))) {
						throw new \InvalidArgumentException("The 'field_normalizer' callback doesn't behave properly because the normalized field name is not a non-empty string");
					}
				}
				if ($opt_field_aliases) {
					$alias = mb_strtolower($name);
					if (array_key_exists($alias, $opt_field_aliases)) {
						$name = $opt_field_aliases[$alias];
					}
				}
				if ($opt_include_fields) {
					if (!in_array($name, $opt_include_fields)) {
						continue;
					}
				}
				if (array_key_exists($name, $this->field_cols)) {
					throw new CSVReaderException('Duplicate field "' . $name . '" detected');
				}
				$this->field_cols[$name] = $x;
			}
			$this->debug && error_log(__METHOD__ . ' Field name => column index pairs: ' . print_r($this->field_cols,1));
		}

		// Check that all the required header fields are present.
		if ($opt_include_fields) {
			$missing = array();
			foreach ($opt_include_fields as $name) {
				if (!array_key_exists($name, $this->field_cols)) {
					array_push($missing, $name);
				}
			}
			if ($missing) {
				throw new CSVReaderException('The following column headers are missing: ' . join(', ', $missing));
			}
		}

		// Read first data row.
		$this->_read();
	}


	/**
	* Destructor.
	*/
	public function __destruct() {
		if ($this->own_h) {
			fclose($this->h);
		}
	}


	/**
	* Peeks into a string of CSV data and tries to guess the delimiter, enclosure, and line separator.
	* Returns an associative array with keys 'line_separator', 'delimiter', 'enclosure'.
	* Undetectable values will be null.
	*
	* @param string $data any length of data, but preferrably long enough to contain at least one whole line.
	* @param string $data_encoding optional
	* @param string $line_separator optional
	* @return array
	*/
	public static function csv_guess($data, $data_encoding = null, $line_separator = null) {
		// TODO: see Perl's Text::CSV::Separator which uses a more advanced approach to detect the delimiter.
		$result = array(
			'line_separator' => null,
			'delimiter'	=> null,
			'enclosure'	=> null,
		);
		$delimiters = array(',', ';', ':', '|', "\t");
		$enclosures = array('"', "'", '');
		$multibyte = $data_encoding && preg_match('/^UTF-(?:16|32)/', $data_encoding);
		if ($multibyte) { // damn multibyte characters
			foreach ($delimiters as &$x) {
				$x = iconv('latin1', $data_encoding, $x);
				unset($x);
			}
			foreach ($enclosures as &$x) {
				$x = iconv('latin1', $data_encoding, $x);
				unset($x);
			}
		}
		// Scan the 1st line only:
		$line = null;
		$cr = "\r";
		$lf = "\n";
		if ($multibyte) {
			$cr = iconv('latin1', $data_encoding, $cr);
			$lf = iconv('latin1', $data_encoding, $lf);
		}
		if (preg_match('/^(.*?)(' . preg_quote("$lf$cr") . '|' . preg_quote("$lf") . '(?!' . preg_quote("$cr") . ')|' . preg_quote("$cr") . '(?!' . preg_quote("$lf") . ')|' . preg_quote("$cr$lf") . ')/', $data, $matches)) {
			$line = $matches[1];
			// Guess line separator
			if (isset($matches[2]) && strlen($matches[2])) {
				$result['line_separator'] = $matches[2];
			}

			// Guess delimiter:
			if (1) {
				$max_count = 0;
				$guessed_delimiter = null;
				foreach ($delimiters as $delimiter) {
					$count = substr_count($line, $delimiter);
					if ($count > $max_count) {
						$max_count = $count;
						$guessed_delimiter = $delimiter;
					}
				}
				$result['delimiter'] = $guessed_delimiter;
			}

			// Guess enclosure
			if ($result['delimiter']) {
				$max_count = 0;
				$guessed_enclosure = null;
				foreach ($enclosures as $enclosure) {
					$count = substr_count($line, $enclosure . $result['delimiter'] . $enclosure);
					if ($count > $max_count) {
						$max_count = $count;
						$guessed_enclosure = $enclosure;
					}
				}
				$result['enclosure'] = $guessed_enclosure;
			}
		}
		return $result;
	}


	/**
	* Reads a CSV line, parses it into an array using str_getcsv(), and performs transcoding if necessary.
	*
	* @param boolean $iconv_strict
	* @return array|false|null
	*/
	protected function _fgetcsv($iconv_strict = false) {
		$line = null;
		if (!$this->seekable && ($this->initial_lines_index < count($this->initial_lines))) {
			$line = $this->initial_lines[$this->initial_lines_index++];
			if ($this->initial_lines_index >= count($this->initial_lines)) {
				$this->initial_lines = array(); // no longer needed, free memory
			}
			//$this->debug && error_log(__METHOD__ . ' got line from initial_lines: ' . bin2hex($line));
		}
		else {
			// Not using fgetcsv() because it is dependent on the locale setting.
			$line = stream_get_line($this->h, $this->length, $this->line_separator);
			if ($line === false) {
				return false;
			}
			//$this->debug && error_log(__METHOD__ . ' read line: ' . bin2hex($line));
		}
		if (!strlen($line)) {
			return array();
		}
		if ($this->must_transcode) {
			//$this->debug && error_log(__METHOD__ . ' transcode string ' . bin2hex($line));
			$to_encoding = $this->internal_encoding;
			if (!$iconv_strict) {
				$to_encoding .= '//IGNORE//TRANSLIT';
			}
			$line = iconv($this->file_encoding, $to_encoding, $line);
			if ($line === false) { // iconv failed
				return false;
			}
		}
		$csv = str_getcsv($line, $this->delimiter, $this->enclosure, $this->escape);
		if (!$csv || (is_null($csv[0]) && (count($csv) == 1))) {
			return array();
		}
		return $csv;
	}


	/**
	* Reads the next CSV data row and sets internal variables.
	* Returns false if EOF was reached, else true.
	* Skips empty lines if option 'skip_empty_lines' is true.
	*
	* @return boolean
	*/
	protected function _read() {
		while (($row = $this->_fgetcsv()) !== false) {
			if ($row) {
				foreach ($row as &$col) {
					if (!is_null($col)) {
						$col = trim($col);
						if (!strlen($col)) {
							$col = null;
						}
					}
				}
				$class = $this->_rowClassName();
				$this->row = new $class($row, $this->field_cols);
				$this->key++;
			}
			else { // blank line
				$this->row = null;
				$this->key++;
				if ($this->skip_empty_lines) {
					continue;
				}
			}
			break;
		}
		if ($row === false) {
			$this->row = null;
			$this->key = -1;
			$this->initial_lines_index = 0;
			return false;
		}
	}


	/**
	* Returns the row class name 'CSVReaderRow'.
	* You may override this method to return a custom row class name.
	*
	* @return string
	*/
	protected function _rowClassName() {
		return 'CSVReaderRow'; // or __CLASS__ . 'Row'
	}


	/**
	* Returns the field names.
	*
	* @return array
	*/
	public function fieldNames() {
		return array_keys($this->field_cols);
	}


	/**
	* Required Iterator interface method.
	*/
	public function current() {
		return $this->row;
	}


	/**
	* Required Iterator interface method.
	*/
	public function key() {
		return $this->key;
	}


	/**
	* Required Iterator interface method.
	*/
	public function next() {
		$this->_read();
	}


	/**
	* Required Iterator interface method.
	*/
	public function rewind() {
		if (!$this->seekable) { // rewind() is called whenever a foreach loop is started.
			return; // Just return without a warning/error.
		}
		if (fseek($this->h, $this->bom_len) != 0) {
			throw new \Exception('Failed to fseek to ' . $this->bom_len);
		}
		$this->row = null;
		$this->key = -1;
		$this->initial_lines_index == 0;
		if ($this->_fgetcsv()) { // skip header row
			$this->_read();
		}
	}


	/**
	* Determines if the stream is seekable.
	*/
	public function seekable() {
		return $this->seekable;
	}


	/**
	* Required Iterator interface method.
	*/
	public function valid() {
		return !is_null($this->row);
	}
}










/**
* Encapsulates a CSV row.
* Created and returned by CSVReader.
* This object should consume less memory than an associative array.
*
* @package  cmanley
*/
class CSVReaderRow implements \Countable, \IteratorAggregate, \ArrayAccess {

	protected $field_cols;
	protected $row;
	protected $pairs; // cached result of toArray()


	/**
	* Constructor.
	*
	* @param array $row
	* @param array $field_cols associative array of fieldname => column index pairs.
	*/
	public function __construct(array $row, array $field_cols) {
		$this->row			= $row;
		$this->field_cols	= $field_cols; // reference counted, not a copy
	}


	/**
	* Returns the value of the field having the given column name.
	*
	* @param string $name
	* @return string|null
	*/
	public function get($name) {
		if (!array_key_exists($name, $this->field_cols)) {
			return null;
		}
		$i = $this->field_cols[$name];
		if ($i >= count($this->row)) {
			return null;
		}
		return $this->row[$i];
	}


	/**
	* Returns the row as an associative array.
	*
	* @param boolean $cacheable default false
	* @return array
	*/
	public function toArray($cacheable = false) {
		$pairs = $this->pairs;
		if (is_null($pairs)) {
			$pairs = array();
			foreach ($this->field_cols as $name => $i) {
				$i = $this->field_cols[$name];
				if ($i >= count($this->row)) {
					continue;
				}
				$pairs[$name] = $this->row[$i];
			}
			if ($cacheable) {
				$this->pairs = $pairs;
			}
		}
		return $pairs;
	}


	/**
	* Return count of items in collection.
	* Implements countable
	*
	* @return integer
	*/
	public function count() {
		return count($this->toArray());
	}


	/**
	* Returns the keys because array_keys() can't (yet).
	*
	* @return array
	*/
	public function keys() {
		return array_keys($this->toArray());
	}


	/**
	* Implements IteratorAggregate
	*
	* @return ArrayIterator
	*/
	public function getIterator() {
		return new \ArrayIterator($this->toArray());
	}


	/**
	* Implements ArrayAccess
	*/
	public function offsetSet($offset, $value) {
		// Ignored because this is readonly
	}


	/**
	* Implements ArrayAccess
	*
	* @return boolean
	*/
	public function offsetExists($offset) {
		return array_key_exists($offset, $this->toArray());
	}


	/**
	* Implements ArrayAccess
	*/
	public function offsetUnset($offset) {
		unset($this->pairs[$offset]);
	}


	/**
	* Implements ArrayAccess
	*
	* @return boolean
	*/
	public function offsetGet($offset) {
		$array = $this->toArray();
		return @$array[$offset];
	}
}
