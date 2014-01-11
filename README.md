PHP-CSVReader
=============

CSV file/stream reader class that implements the Iterator interface.
It supports files having a (UTF-8 or UTF-*) BOM, as well as non-seekable streams.
It is capable of auto-detecting stream encoding, line seperators, field seperators, field enclosures.
Many options can be passed into the constructor in order to influence it's behaviour.
See the PHP documentation in CSVReader.php for more info.

Synopsis:
---------
```
// read a plain CSV file
$reader = new CSVReader('products.csv');

// read a gzipped CSV file
$reader = new CSVReader('compress.zlib://products.csv.gz');

// read CSV from STDIN
$reader = new CSVReader('php://stdin');

// read products.csv from within export.zip archive
$reader = new CSVReader('zip://export.zip#products.csv');

// read from a open file handle
$h = fopen('products.csv', 'r');
$reader = new CSVReader($h);

// Show fieldnames from 1st row:
print_r($reader->fieldNames());

// Iterate over all the rows. CVSReader behaves as an array.
foreach($reader as $row) { // $row is a CSVReaderRow object
	print 'Price: ' . $row->get('Price') . "\n";
	print 'Name: ' . $row['Name'] . "\n"; // $row also supports array access
	print_r($row->toArray());
	// TIP: Use PHP-Validate, by your's truly, to validate $row->toArray()
}
```
