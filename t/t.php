<?php
namespace PHPUnit\Framework;
if (isset($argv)) {
	print "Running outside of phpunit. Consider using phpunit.\n";
	class TestCase {};
}

class T extends \PHPUnit\Framework\TestCase
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
		$interfaces = [
			'\\Iterator',
		];
		foreach ($interfaces as $interface) {
			$this->assertTrue(is_subclass_of ($class, $interface, true), "Check that class $class implements $interface");
		}
	}

	public function testMethodsExist() {
		$class = static::NAMESPACE_NAME . '\\' . static::CLASS_NAME;
		$methods = [
			'__construct'	=> \ReflectionMethod::IS_PUBLIC,
			'__destruct'	=> \ReflectionMethod::IS_PUBLIC,
			'_transcode'	=> \ReflectionMethod::IS_PROTECTED | \ReflectionMethod::IS_STATIC,
			'csv_guess'		=> \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC,
			'fieldNames'	=> \ReflectionMethod::IS_PUBLIC,
			'_fgetcsv'		=> \ReflectionMethod::IS_PROTECTED,

			# Iterator interface methods:
			'current'		=> \ReflectionMethod::IS_PUBLIC,
			'key'			=> \ReflectionMethod::IS_PUBLIC,
			'next'			=> \ReflectionMethod::IS_PUBLIC,
			'rewind'		=> \ReflectionMethod::IS_PUBLIC,
			'valid'			=> \ReflectionMethod::IS_PUBLIC,
		];
		foreach ($methods as $name => $expected_modifiers) {
			$exists = method_exists($class, $name);
			$this->assertTrue($exists, "Check method $class::$name() exists.");
			if ($exists) {
				$method = new \ReflectionMethod($class, $name);
				$actual_modifiers = $method->getModifiers() & (
					\ReflectionMethod::IS_STATIC |
					\ReflectionMethod::IS_PUBLIC |
					\ReflectionMethod::IS_PROTECTED |
					\ReflectionMethod::IS_PRIVATE |
					\ReflectionMethod::IS_ABSTRACT |
					\ReflectionMethod::IS_FINAL
				);
				#error_log("$name expected: " . $expected_modifiers);
				#error_log("$name actual:   " . $actual_modifiers);
				$this->assertEquals($expected_modifiers, $actual_modifiers, "Expected $class::$name() modifiers to be \"" . join(' ', \Reflection::getModifierNames($expected_modifiers)) . '" but got "' . join(' ', \Reflection::getModifierNames($actual_modifiers)) . '" instead.');
			}
		}
	}

	public function testCreate() {
		$class = static::NAMESPACE_NAME . '\\' . static::CLASS_NAME;
		foreach ([
			'csv',
			'tsv',
			'csv.gz',
		] as $ext) {
			$stream = $file = "iso639lang.$ext";
			if (preg_match('/\.gz$/', $ext)) {
				$stream = 'compress.zlib://' . $stream;
			}
			$reader = new $class($stream);
			$expected_field_names = [
				'ISO 639-1 alpha-2',
				'ISO 639-2 alpha-3',
				'ISO 639 English name of language',
				'ISO 639 French name of language',
			];
			$this->assertEquals(var_export($expected_field_names,1), var_export($reader->fieldNames(), true), 'fieldNames()');
			#foreach($reader as $row) { // $row is an associative array
			#	print $row['ISO 639-2 alpha-3'] . "\t" . $row['ISO 639 French name of language'] . "\n";
			#	print_r($row);
			#}
		}
	}

	public function testIterator() {
		$class = static::NAMESPACE_NAME . '\\' . static::CLASS_NAME;
		$stream = 'users.csv';
		$reader = new $class($stream);
		$row = $reader->current();
		$expect = 'Chris';
		$this->assertEquals($expect, $row['First Name'], "First record contains $expect");
		foreach($reader as $row) {}
		$expect = 'Melissa';
		$this->assertEquals($expect, $row['First Name'], "Last record contains $expect");
		$reader->rewind();
		$row = $reader->current();
		$expect = 'Chris';
		$this->assertEquals($expect, $row['First Name'], "First record contains $expect after rewind");
	}

}


if (isset($argv)) {
	$class = T::NAMESPACE_NAME . '\\' . T::CLASS_NAME;
	require_once(T::FILE);
	foreach ([
		'csv',
		'tsv',
		'csv.gz',
	] as $ext) {
		$stream = $file = "iso639lang.$ext";
		if (preg_match('/\.gz$/', $ext)) {
			$stream = 'compress.zlib://' . $stream;
		}
		$reader = new $class($stream, [
			'debug'	=> true,
		]);
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
