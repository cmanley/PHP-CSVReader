<?php
/**
* Contains the CSVReader, and CSVReaderException classes.
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
* @version   $Id: CSVReader.php,v 1.23 2024/02/18 00:17:41 cmanley Exp $
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
* @package  cmanley
*/
class CSVReader implements Iterator {

	protected $h; # File handle.
	protected $own_h; # Does this class own the file handle.
	protected $seekable; # Is the stream seekable?
	protected $initial_lines = []; # For non-seekable streams: pre-read lines for inspecting BOM and encoding.
	protected $initial_lines_index = 0; # For non-seekable streams: index of which pre-read lines to read next. Used by _fgetcsv().
	protected $bom_len = 0; # Contains the BOM length.
	protected $field_cols = []; # Associative array of fieldname => column index pairs.
	protected $row; # Current row (associative array).
	protected $key = -1; # Data row index.
	protected $must_transcode = false; # true if file encoding does not match internal encoding.

	# Options:
	protected $debug = false;
	protected $internal_encoding = null;
	protected $skip_empty_lines = false;

	protected $file_encoding = null;
	protected $length = 4096;
	protected $delimiter = null; # ',';
	protected $enclosure = null; # '"';
	protected $escape = '\\';
	protected $line_separator; # For UTF-16LE it's multibyte, e.g. 0x0A00

	/**
	* Constructor.
	*
	* @param string|resource $file file name or handle opened for reading.
	* @param array $option optional associative array of any of these options:
	*	- debug: boolean, if true, then debug messages are emitted using error_log().
	*	- field_aliases: associative array of case insensitive field name alias (in file) => real name (as expected in code) pairs.
	*	- field_normalizer: optional callback that receives a field name by reference to normalize (e.g. make lowercase).
	*	- include_fields: optional array of field names to include. If given, then all other field names are excluded.
	*	- internal_encoding, default is mb_internal_encoding(), only effective if 'file_encoding' is given or detected.
	*	- skip_empty_lines, default false
	*
	*	- delimiter: string, guessed if not given with default ',', see str_getcsv()
	*	- enclosure: string, guessed if not given with default '"', see str_getcsv()
	*	- escape: string, default backslash, see str_getcsv()
	*	- file_encoding, default null, which means guess encoding using BOM or mb_detect_encoding().
	*	- length: string, default 4096, see stream_get_line()
	*	- line_separator: string, if not given, then it's guessed.
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
			$options = [];
		}

		# Get the options.
		$opt_field_aliases = null;
		$opt_field_normalizer = null;
		$opt_include_fields = null;
		if ($options) {
			foreach ($options as $key => $value) {
				if (is_null($value)) {
					continue;
				}
				if (in_array($key, ['debug', 'skip_empty_lines'])) {
					if (!(is_bool($value) || is_int($value))) {
						throw new \InvalidArgumentException("The '$key' option must be a boolean");
					}
					$this->$key = $value;
				}
				elseif (in_array($key, ['enclosure', 'escape', 'line_separator'])) {
					if (!is_string($value)) {
						throw new \InvalidArgumentException("The '$key' option must be a string");
					}
					$this->$key = $value;
				}
				elseif (in_array($key, ['delimiter', 'file_encoding', 'internal_encoding'])) {
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
					$opt_field_aliases = [];
					foreach ($value as $k => $v) {
						$opt_field_aliases[mb_strtolower($k)] = $v;
					}
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

		# Read the BOM, if any.
		if (1) {
			$line = fread($this->h, 4); # incomplete line!
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
				if (!$this->line_separator && $this->bom_len && ($this->file_encoding != 'UTF-8')) { # A string with multibyte line separators. Can't use fgets() here because it doesn't support multibyte line separators.
					if ($this->file_encoding === 'UTF-16LE') {
						$this->line_separator = "\x0A\x00";
						$line .= stream_get_line($this->h, $this->length, $this->line_separator);
						if (substr($line, -2) == "\x0D\x00") {
							$this->line_separator = "\x0D\x00" . $this->line_separator;
							$line = substr($line, 0, strlen($line) - 2);
						}
					}
					elseif ($this->file_encoding === 'UTF-16BE') {
						$this->line_separator = "\x00\x0A";
						$line .= stream_get_line($this->h, $this->length, $this->line_separator);
						if (substr($line, -2) == "\x00\x0D") {
							$this->line_separator = "\x00\x0D" . $this->line_separator;
							$line = substr($line, 0, strlen($line) - 2);
						}
					}
					elseif ($this->file_encoding === 'UTF-32LE') {
						$this->line_separator = "\x0A\x00\x00\x00";
						$line .= stream_get_line($this->h, $this->length, $this->line_separator);
						if (substr($line, -4) == "\x0D\x00\x00\x00") {
							$this->line_separator = "\x0D\x00\x00\x00" . $this->line_separator;
							$line = substr($line, 0, strlen($line) - 4);
						}
					}
					elseif ($this->file_encoding === 'UTF-32BE') {
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

		# Guess some options if necessary by sniffing a chunk of data from the file.
		if (is_null($this->delimiter) || is_null($this->enclosure) || !$this->file_encoding || !$this->line_separator) {
			# Read some (more) lines from the input to use for inspection.
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
			# If delimiter or enclosure are not given, try to guess them.
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

			# Guess file encoding if unknown.
			if (!$this->file_encoding) {
				$encodings = array_unique(array_merge(
					$this->internal_encoding ? [$this->internal_encoding] : [],
					mb_detect_order(),
					['UTF-32BE', 'UTF-32LE', 'UTF-16BE', 'UTF-16LE', 'UTF-8', 'Windows-1252', 'cp1252', 'ISO-8859-1'] # common file encodings
				));
				$this->debug && error_log(__METHOD__ . ' Guessing file encoding using encodings: ' . join(', ', $encodings));
				$this->file_encoding = mb_detect_encoding($s, $encodings, true);
				unset($s, $encodings);
				$this->debug && error_log(__METHOD__ . ' Guessed file encoding: ' . $this->file_encoding);
			}

			# Guess line separator.
			if (!$this->line_separator) {
				if (preg_match('/^UTF-(?:16|32)/', $this->file_encoding)) { # Multibyte line separators. Can't use fgets() here because it doesn't support multibyte line separators.
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
				$this->debug && error_log(__METHOD__ . ' Guessed line separator: ' . ' (0x' . bin2hex($this->line_separator) . ')');
			}
		}

		# Determine if transcoding is necessary for _fgetcsv().
		if ($this->file_encoding && $this->internal_encoding && strcasecmp($this->file_encoding, $this->internal_encoding)) {
			$this->must_transcode = true;
			# Try to gain effeciency here by eliminating transcoding if file encoding is a subset or alias of internal encoding.
			if ($this->file_encoding == 'ASCII') {
				if (in_array($this->internal_encoding, ['UTF-8', 'Windows-1252', 'cp1252', 'ISO-8859-1'])) {
					$this->must_transcode = false;
				}
			}
			elseif ($this->file_encoding == 'ISO-8859-1') {
				if (in_array($this->internal_encoding, ['Windows-1252', 'cp1252'])) {
					$this->must_transcode = false;
				}
			}
			elseif (preg_replace('/^(?:cp|windows)-?/', '', strtolower($this->file_encoding)) == preg_replace('/^(?:cp|windows)-?/', '', strtolower($this->internal_encoding))) {
				$this->must_transcode = false;
			}
		}
		$this->debug && error_log(__METHOD__ . ' Must transcode: ' . var_export($this->must_transcode, 1));

		# Read header row.
		#@trigger_error('');
		if ($row = $this->_fgetcsv()) {
			$this->debug && error_log(__METHOD__ . ' Raw header row: ' . print_r($row,1));
			$unknown_fieldinfo = []; # field name => col index pairs

			# Get the fieldname => column indices
			$x = 0;
			for ($x = 0; $x < count($row); $x++) {
				$name = trim($row[$x]);
				if (!(is_string($name) && strlen($name))) {
					continue;
				}
				if ($this->must_transcode) {
					$name = static::_transcode($this->file_encoding, $this->internal_encoding, $name);
				}
				if ($opt_field_normalizer) {
					call_user_func_array($opt_field_normalizer, [&$name]);
					if (!(is_string($name) && strlen($name))) {
						throw new \InvalidArgumentException("The 'field_normalizer' callback doesn't behave properly because the normalized field name is not a non-empty string");
					}
				}
				if ($opt_field_aliases) {
					$alias = mb_strtolower($name, $this->internal_encoding);
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

		# Check that all the required header fields are present.
		if ($opt_include_fields) {
			$missing = [];
			foreach ($opt_include_fields as $name) {
				if (!array_key_exists($name, $this->field_cols)) {
					$missing []= $name;
				}
			}
			if ($missing) {
				throw new CSVReaderException('The following column headers are missing: ' . join(', ', $missing));
			}
		}

		# Read first data row.
		$this->next();
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
	* Transcodes the given string.
	*
	* @param string $value
	* @param string $value
	* @param string $value
	* @return string
	*/
	protected static function _transcode(string $encoding_from, string $encoding_to, $value = null) {
		if (!(is_string($value) && strlen($value))) {
			return $value;
		}
		return mb_convert_encoding($value, $encoding_to, $encoding_from);
	}


	/**
	* Peeks into a string of CSV data and tries to guess the delimiter, enclosure, and line separator.
	* Returns an associative array with keys 'line_ending', 'delimiter', 'enclosure'.
	* Undetectable values will be null.
	*
	* @param string $data any length of data, but preferrably long enough to contain at least one whole line.
	* @param string $data_encoding optional
	* @return array
	*/
	public static function csv_guess(string $data, string $data_encoding = null): array {
		# TODO: see Perl's Text::CSV::Separator which uses a more advanced approach to detect the delimiter.
		$result = [
			'line_ending' => null,
			'delimiter'	=> null,
			'enclosure'	=> null,
		];
		$delimiters = [',', ';', ':', '|', "\t"];
		$enclosures = ['"', "'", ''];
		$eols_single = ["\n", "\r"];
		$eols_multi = ["\r\n", "\n\r"];

		$multibyte = $data_encoding && is_string($data_encoding) && preg_match('/^UTF-(?:16|32)/', $data_encoding);
		if ($multibyte) { # damn multibyte delimiters, enclosures, and line endings
			foreach ($delimiters as &$x) {
				$x = static::_transcode('latin1', $data_encoding, $x);
				unset($x);
			}
			foreach ($enclosures as &$x) {
				$x = static::_transcode('latin1', $data_encoding, $x);
				unset($x);
			}
			foreach ($eols_single as &$x) {
				$x = static::_transcode('latin1', $data_encoding, $x);
				unset($x);
			}
			foreach ($eols_multi as &$x) {
				$x = static::_transcode('latin1', $data_encoding, $x);
				unset($x);
			}
		}

		# Guess line ending
		$guessed_eol = null;
		if (1) {
			$max_count = 0;
			foreach ($eols_multi as $eol) {
				$count = substr_count($data, $eol);
				if ($count > $max_count) {
					$max_count = $count;
					$guessed_eol = $eol;
				}
			}
			if (is_null($guessed_eol)) {
				foreach ($eols_single as $eol) {
					$count = substr_count($data, $eol);
					if ($count > $max_count) {
						$max_count = $count;
						$guessed_eol = $eol;
					}
				}
			}
			$result['line_ending'] = $guessed_eol;
		}

		# Remove line endings
		$data = str_replace($guessed_eol, '', $data);

		# Guess delimiter:
		if (1) {
			$max_count = 0;
			$guessed_delimiter = null;
			foreach ($delimiters as $delimiter) {
				$count = substr_count($data, $delimiter);
				if ($count > $max_count) {
					$max_count = $count;
					$guessed_delimiter = $delimiter;
				}
			}
			$result['delimiter'] = $guessed_delimiter;
		}

		# Guess enclosure
		if (!is_null($result['delimiter'])) {
			$max_count = 0;
			$guessed_enclosure = '';
			foreach ($enclosures as $enclosure) {
				$count = substr_count($data, $enclosure . $result['delimiter'] . $enclosure);
				if ($count > $max_count) {
					$max_count = $count;
					$guessed_enclosure = $enclosure;
				}
			}
			$result['enclosure'] = $guessed_enclosure;
		}

		return $result;
	}


	/**
	* Reads a CSV line, parses it into an array using str_getcsv(), and performs transcoding if necessary.
	*
	* @return array|false
	*/
	protected function _fgetcsv() {
		$line = null;
		if (!$this->seekable && ($this->initial_lines_index < count($this->initial_lines))) {
			$line = $this->initial_lines[$this->initial_lines_index++];
			if ($this->initial_lines_index >= count($this->initial_lines)) {
				$this->initial_lines = []; # no longer needed, free memory
			}
			#$this->debug && error_log(__METHOD__ . ' got line from initial_lines: ' . bin2hex($line));
		}
		else {
			# Not using fgetcsv() because it is dependent on the locale setting.
			$line = stream_get_line($this->h, $this->length, $this->line_separator);
			if (($line === false) || (($line === '') && feof($this->h))) { # feof() check is needed for PHP < 5.4.4 because stream_get_line() kept returning an empty string instead of false at eof.
				return false;
			}
			#$this->debug && error_log(__METHOD__ . ' read line: ' . bin2hex($line));
		}
		if (!strlen($line)) {
			return [];
		}
		if ($this->must_transcode) {
			#$this->debug && error_log(__METHOD__ . ' transcode string ' . bin2hex($line));
			$line = static::_transcode($this->file_encoding, $this->internal_encoding, $line);
			if ($line === false) {
				return false;
			}
		}
		$csv = str_getcsv($line, $this->delimiter, $this->enclosure, $this->escape);
		if (!$csv || (is_null(reset($csv) && (count($csv) == 1)))) {
			return [];
		}
		return $csv;
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
	#[\ReturnTypeWillChange]
	public function current() {	# PHP >= 8: use mixed
		return $this->row;
	}


	/**
	* Required Iterator interface method.
	*/
	#[\ReturnTypeWillChange]
	public function key() {	# PHP >= 8: use mixed
		return $this->key;
	}


	/**
	* Required Iterator interface method.
	* Reads the next CSV data row and sets internal variables.
	* Returns false if EOF was reached, else true.
	* Skips empty lines if option 'skip_empty_lines' is true.
	*/
	public function next(): void {
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
				# Convert row to associative array
				$pairs = [];
				foreach ($this->field_cols as $name => $i) {
					$i = $this->field_cols[$name];
					if ($i >= count($row)) {
						continue;
					}
					$pairs[$name] = $row[$i];
				}
				$this->row = $pairs;
				$this->key++;
			}
			else { # blank line
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
		}
	}


	/**
	* Required Iterator interface method.
	*/
	public function rewind(): void {
		if (!$this->seekable) { # rewind() is called whenever a foreach loop is started.
			return; # Just return without a warning/error.
		}
		if (fseek($this->h, $this->bom_len) != 0) {
			throw new \Exception('Failed to fseek to ' . $this->bom_len);
		}
		$this->row = null;
		$this->key = -1;
		$this->initial_lines_index == 0;
		if ($this->_fgetcsv()) { # skip header row
			$this->next();
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
	public function valid(): bool {
		return !is_null($this->row);
	}
}
