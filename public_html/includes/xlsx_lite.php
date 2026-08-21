<?php
/**
 * xlsx_lite.php
 *
 * Tiny dependency-free .xlsx reader/writer used ONLY by the WHO reference
 * table import/export feature (nutritionist/who_reference.php). No Composer
 * packages are installed in this project, so this talks to the raw OOXML
 * (a .xlsx is just a zip of XML files) instead of pulling in PhpSpreadsheet.
 *
 * It intentionally does NOT try to be a general-purpose Excel library: no
 * styles, no formulas, no multi-sheet support. It reads/writes exactly what
 * the WHO reference import/export screens need:
 *   - read: the first worksheet of an uploaded .xlsx as a plain array of rows
 *   - write: a single-sheet .xlsx from a header row + data rows
 */

function xlsx_lite_require_zip(): void
{
	if (!class_exists('ZipArchive')) {
		throw new RuntimeException('The PHP "zip" extension is required to read/write .xlsx files. Enable it in php.ini (extension=zip) and reload PHP.');
	}
}

/**
 * Reads the first worksheet of an .xlsx file into a plain array of rows.
 * Each row is a 0-indexed array of string cell values, padded so every row
 * has the same length as the widest row seen. Blank cells are ''.
 *
 * @return string[][]
 */
function xlsx_lite_read_first_sheet(string $path): array
{
	xlsx_lite_require_zip();

	$zip = new ZipArchive();
	$opened = $zip->open($path);

	if ($opened !== true) {
		throw new RuntimeException('That file could not be opened as an .xlsx workbook (it may be corrupted or not really an Excel file).');
	}

	$sharedStrings = xlsx_lite_read_shared_strings($zip);
	$sheetXml = xlsx_lite_read_first_sheet_xml($zip);
	$zip->close();

	if ($sheetXml === null) {
		throw new RuntimeException('That workbook does not contain a readable worksheet.');
	}

	return xlsx_lite_parse_sheet_xml($sheetXml, $sharedStrings);
}

/**
 * @return string[] shared strings, indexed by their position in sharedStrings.xml
 */
function xlsx_lite_read_shared_strings(ZipArchive $zip): array
{
	$xml = $zip->getFromName('xl/sharedStrings.xml');

	if ($xml === false) {
		return [];
	}

	$prev = libxml_use_internal_errors(true);
	$doc = simplexml_load_string($xml);
	libxml_use_internal_errors($prev);

	if ($doc === false) {
		return [];
	}

	$doc->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
	$strings = [];

	foreach ($doc->xpath('//a:si') as $si) {
		// A shared string entry is either a plain <t> or one or more rich-text
		// <r><t>...</t></r> runs that need to be concatenated.
		$text = '';
		$directT = $si->xpath('./a:t');

		if ($directT !== [] && $directT !== null) {
			$text = (string)$directT[0];
		} else {
			foreach ($si->xpath('.//a:r/a:t') as $run) {
				$text .= (string)$run;
			}
		}

		$strings[] = $text;
	}

	return $strings;
}

function xlsx_lite_read_first_sheet_xml(ZipArchive $zip): ?string
{
	// Resolve the first <sheet> in workbook.xml to its real file inside the
	// zip via workbook.xml.rels, instead of assuming xl/worksheets/sheet1.xml
	// (that's true for files this app exports, but not guaranteed for every
	// .xlsx someone might upload).
	$workbookXml = $zip->getFromName('xl/workbook.xml');
	$relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

	if ($workbookXml !== false && $relsXml !== false) {
		$prev = libxml_use_internal_errors(true);
		$workbook = simplexml_load_string($workbookXml);
		$rels = simplexml_load_string($relsXml);
		libxml_use_internal_errors($prev);

		if ($workbook !== false && $rels !== false) {
			$workbook->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
			$workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

			$sheets = $workbook->xpath('//a:sheets/a:sheet');

			if ($sheets !== null && $sheets !== []) {
				$rId = (string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

				foreach ($rels->children() as $rel) {
					if ((string)$rel['Id'] === $rId) {
						$target = ltrim((string)$rel['Target'], '/');

						if (!str_starts_with($target, 'xl/')) {
							$target = 'xl/' . $target;
						}

						$xml = $zip->getFromName($target);

						if ($xml !== false) {
							return $xml;
						}
					}
				}
			}
		}
	}

	// Fall back to the conventional path used by nearly every real-world
	// single-sheet export (including the ones this app writes).
	$fallback = $zip->getFromName('xl/worksheets/sheet1.xml');

	return $fallback !== false ? $fallback : null;
}

/**
 * @return string[][]
 */
function xlsx_lite_parse_sheet_xml(string $sheetXml, array $sharedStrings): array
{
	$prev = libxml_use_internal_errors(true);
	$doc = simplexml_load_string($sheetXml);
	libxml_use_internal_errors($prev);

	if ($doc === false) {
		throw new RuntimeException('That worksheet could not be parsed.');
	}

	$doc->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

	$rows = [];
	$maxCols = 0;

	foreach ($doc->xpath('//a:sheetData/a:row') as $rowNode) {
		$row = [];

		foreach ($rowNode->xpath('./a:c') as $cellNode) {
			$ref = (string)$cellNode['r'];
			$colIndex = xlsx_lite_column_ref_to_index($ref);
			$type = (string)$cellNode['t'];

			if ($type === 's') {
				$idx = (int)(string)$cellNode->v;
				$value = $sharedStrings[$idx] ?? '';
			} elseif ($type === 'inlineStr') {
				$isNode = $cellNode->xpath('./a:is/a:t');
				$value = $isNode !== null && $isNode !== [] ? (string)$isNode[0] : '';
			} else {
				$value = isset($cellNode->v) ? (string)$cellNode->v : '';
			}

			$row[$colIndex] = $value;

			if ($colIndex + 1 > $maxCols) {
				$maxCols = $colIndex + 1;
			}
		}

		if ($row !== []) {
			ksort($row);
			$rows[] = $row;
		} else {
			$rows[] = [];
		}
	}

	// Pad every row to the same width so callers can use plain numeric offsets.
	foreach ($rows as &$row) {
		for ($i = 0; $i < $maxCols; $i++) {
			if (!array_key_exists($i, $row)) {
				$row[$i] = '';
			}
		}

		ksort($row);
		$row = array_values($row);
	}
	unset($row);

	return $rows;
}

/** Converts a cell reference like "C7" into a 0-indexed column number (A=0). */
function xlsx_lite_column_ref_to_index(string $ref): int
{
	if (preg_match('/^([A-Z]+)/', strtoupper($ref), $m) !== 1) {
		return 0;
	}

	$letters = $m[1];
	$index = 0;

	for ($i = 0; $i < strlen($letters); $i++) {
		$index = ($index * 26) + (ord($letters[$i]) - 64);
	}

	return $index - 1;
}

/**
 * Writes a single-sheet .xlsx file made of a header row followed by data
 * rows. All values are written as inline strings so no shared-strings table
 * is needed, which keeps the writer side short.
 *
 * @param string[] $headerRow
 * @param array<int, array<int, string|int|float>> $dataRows
 */
function xlsx_lite_write(string $path, array $headerRow, array $dataRows, string $sheetName = 'Sheet1'): bool
{
	xlsx_lite_require_zip();

	$zip = new ZipArchive();

	if (file_exists($path)) {
		@unlink($path);
	}

	if ($zip->open($path, ZipArchive::CREATE) !== true) {
		return false;
	}

	$safeSheetName = substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $sheetName), 0, 31) ?: 'Sheet1';

	$zip->addFromString('[Content_Types].xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
		'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
		'<Default Extension="xml" ContentType="application/xml"/>' .
		'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
		'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
		'</Types>'
	);

	$zip->addFromString('_rels/.rels',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
		'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
		'</Relationships>'
	);

	$zip->addFromString('xl/_rels/workbook.xml.rels',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
		'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
		'</Relationships>'
	);

	$zip->addFromString('xl/workbook.xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
		'<sheets><sheet name="' . htmlspecialchars($safeSheetName, ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets>' .
		'</workbook>'
	);

	$allRows = array_merge([$headerRow], $dataRows);
	$sheetBody = '<sheetData>';

	foreach ($allRows as $rowIndex => $row) {
		$excelRow = $rowIndex + 1;
		$sheetBody .= '<row r="' . $excelRow . '">';

		foreach (array_values($row) as $colIndex => $value) {
			$cellRef = xlsx_lite_index_to_column_ref($colIndex) . $excelRow;

			if (is_int($value) || is_float($value)) {
				$sheetBody .= '<c r="' . $cellRef . '"><v>' . xlsx_lite_number_to_string($value) . '</v></c>';
			} else {
				$stringValue = (string)$value;

				if ($stringValue !== '' && is_numeric($stringValue)) {
					$sheetBody .= '<c r="' . $cellRef . '"><v>' . htmlspecialchars($stringValue, ENT_XML1) . '</v></c>';
				} else {
					$sheetBody .= '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars($stringValue, ENT_XML1) . '</t></is></c>';
				}
			}
		}

		$sheetBody .= '</row>';
	}

	$sheetBody .= '</sheetData>';

	$zip->addFromString('xl/worksheets/sheet1.xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
		$sheetBody .
		'</worksheet>'
	);

	$zip->close();

	return true;
}

function xlsx_lite_index_to_column_ref(int $index): string
{
	$index++;
	$ref = '';

	while ($index > 0) {
		$rem = ($index - 1) % 26;
		$ref = chr(65 + $rem) . $ref;
		$index = intdiv($index - 1, 26);
	}

	return $ref;
}

function xlsx_lite_number_to_string(int|float $value): string
{
	if (is_int($value)) {
		return (string)$value;
	}

	// Avoid scientific notation / floating noise in the exported cells.
	return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
}