<?php
if (isset($argv)) {
	print "Running outside of phpunit. Consider using phpunit.\n";
	class PHPUnit_Framework_TestCase {}
}


class Test extends PHPUnit_Framework_TestCase
{
	const NAMESPACE_NAME = '';
	const CLASS_NAME = 'CSVReader';
	#const FILE = __DIR__ . '/../' . self::CLASS_NAME . '.php'; # >= PHP 5.6
	const FILE = '../CSVReader.php';

    public function testRequire() {
        $this->assertFileExists(static::FILE);
        include(static::FILE);
        $class = static::NAMESPACE_NAME . '\\' . static::CLASS_NAME;
        $this->assertTrue(class_exists($class), 'Check that class name "' . $class . '" exists after include.');
    }

	public function testImplementsInterface() {
		$class = static::NAMESPACE_NAME . '\\' . static::CLASS_NAME;
		$interfaces = array('\\Iterator');
		foreach ($interfaces as $interface) {
			$this->assertTrue(is_subclass_of ($class, $interface, true), "Check that class $class implements $interface");
		}
	}

	public function testMethodsExist() {
		$class = static::NAMESPACE_NAME . '\\' . static::CLASS_NAME;
		$methods = array(
			'__construct',
			'__destruct',
			'_transcode',		// static
			'csv_guess',		// static
			'fieldNames',
			'_fgetcsv',
			'seekable',

			'current',			// Iterator interface
			'key',				// Iterator interface
			'next',				// Iterator interface
			'rewind',			// Iterator interface
			'valid',			// Iterator interface
		);
		foreach ($methods as $method) {
			$this->assertTrue(method_exists($class, $method), "Check method $class::$method() exists.");
		}
	}

	public function testCreate() {
		$class = static::NAMESPACE_NAME . '\\' . static::CLASS_NAME;
		foreach (array(
			'csv',
			'tsv',
			'csv.gz',
		) as $ext) {
			$stream = $file = "iso639lang.$ext";
			if (preg_match('/\.gz$/', $ext)) {
				$stream = 'compress.zlib://' . $stream;
			}
			$reader = new $class($stream);
			$expected_field_names = array(
				'ISO 639-1 alpha-2',
				'ISO 639-2 alpha-3',
				'ISO 639 English name of language',
				'ISO 639 French name of language',
			);
			$this->assertEquals(var_export($expected_field_names,1), var_export($reader->fieldNames(),1), 'fieldNames()');
			#foreach($reader as $row) { // $row is an associative array
			#	print $row['ISO 639-2 alpha-3'] . "\t" . $row['ISO 639 French name of language'] . "\n";
			#	print_r($row);
			#}
		}
	}

	public function testIterator() {
		$class = static::NAMESPACE_NAME . '\\' . static::CLASS_NAME;
		$stream = 'users.csv';
		$reader = new CSVReader($stream);
		$row = $reader->current();
		$this->assertEquals('Chris', $row['First Name'], 'First record contains Chris');
		#error_log(print_r($row,1));
		foreach($reader as $row) { // $row is an associative array
			# nop
		}
		$this->assertEquals('Melissa', $row['First Name'], 'Last record contains Melissa');
		#error_log(print_r($row,1));
	}

}


if (isset($argv)) {
	$class = Test::NAMESPACE_NAME . '\\' . Test::CLASS_NAME;
	require_once(Test::FILE);
	foreach (array(
		'csv',
		'tsv',
		'csv.gz',
	) as $ext) {
		$stream = $file = "iso639lang.$ext";
		if (preg_match('/\.gz$/', $ext)) {
			$stream = 'compress.zlib://' . $stream;
		}
		$reader = new $class($stream, array(
			'debug'	=> true,
		));
		print_r($reader->fieldNames());
		foreach($reader as $row) { // $row is an associative array
			#if ($row['ISO 639-1 alpha-2'] == 'bo') {	#"bo","tib (B)","Tibetan","tibétain"
			if ($row['ISO 639-2 alpha-3'] == 'arc') {	#,arc,Official Aramaic (700-300 BCE)/Imperial Aramaic (700-300 BCE),araméen d'empire (700-300 BCE)
				print_r($row);
				break;
			}
		}
	}
}
