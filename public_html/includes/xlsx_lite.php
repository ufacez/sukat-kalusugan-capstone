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
 * formulas, no charts, no images. It reads/writes exactly what this app
 * needs:
 *   - read: the first worksheet of an uploaded .xlsx as a plain array of rows
 *   - write: a single-sheet .xlsx from a header row + data rows
 *             (xlsx_lite_write — WHO reference import/export screens)
 *   - write: a styled multi-sheet .xlsx workbook with bordered grids, merged
 *             title blocks, and column widths (xlsx_lite_write_workbook —
 *             the EOPT report exports)
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

		// Namespace registrations do NOT propagate from the root document
		// to child nodes on every libxml build — re-register per row.
		$rowNode->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

		foreach ($rowNode->xpath('./a:c') as $cellNode) {
			$ref = (string)$cellNode['r'];
			$colIndex = xlsx_lite_column_ref_to_index($ref);
			$type = (string)$cellNode['t'];

			if ($type === 's') {
				$idx = (int)(string)$cellNode->v;
				$value = $sharedStrings[$idx] ?? '';
			} elseif ($type === 'inlineStr') {
				$cellNode->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
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
 * Thin wrapper over xlsx_lite_write_workbook() — kept for the WHO reference
 * import/export screens which predate the styled multi-sheet writer used by
 * the EOPT report exports.
 *
 * @param string[] $headerRow
 * @param array<int, array<int, string|int|float>> $dataRows
 */
function xlsx_lite_write(string $path, array $headerRow, array $dataRows, string $sheetName = 'Sheet1'): bool
{
	$rows = [];

	$headerCells = [];
	foreach (array_values($headerRow) as $value) {
		$headerCells[] = ['v' => $value, 's' => 'header'];
	}
	$rows[] = $headerCells;

	foreach ($dataRows as $dataRow) {
		$cells = [];
		foreach (array_values($dataRow) as $value) {
			$cells[] = ['v' => $value];
		}
		$rows[] = $cells;
	}

	return xlsx_lite_write_workbook($path, [
		['name' => $sheetName, 'rows' => $rows],
	]);
}

/*
|--------------------------------------------------------------------------
| Named cell styles shared by every EOPT export sheet. Each entry maps a
| friendly key to the font/fill/border/alignment combo written into
| styles.xml; cells reference them by index via the s="" attribute.
|--------------------------------------------------------------------------
*/
const XLSX_LITE_STYLES = [
	'default'     => ['font' => 0, 'fill' => 0, 'border' => 0, 'align' => null],
	'org'         => ['font' => 2, 'fill' => 0, 'border' => 0, 'align' => 'center'],
	'title'       => ['font' => 1, 'fill' => 0, 'border' => 0, 'align' => 'center'],
	'subtitle'    => ['font' => 2, 'fill' => 0, 'border' => 0, 'align' => 'center'],
	'label'       => ['font' => 2, 'fill' => 0, 'border' => 0, 'align' => 'left'],
	'field'       => ['font' => 0, 'fill' => 0, 'border' => 0, 'align' => 'left'],
	'header'      => ['font' => 2, 'fill' => 2, 'border' => 1, 'align' => 'center', 'wrap' => true],
	'header_left' => ['font' => 2, 'fill' => 2, 'border' => 1, 'align' => 'left'],
	'cell'        => ['font' => 0, 'fill' => 0, 'border' => 1, 'align' => 'left'],
	'cell_center' => ['font' => 0, 'fill' => 0, 'border' => 1, 'align' => 'center'],
	'cell_num'    => ['font' => 0, 'fill' => 0, 'border' => 1, 'align' => 'right'],
	'total'       => ['font' => 2, 'fill' => 2, 'border' => 1, 'align' => 'right'],
	'total_label' => ['font' => 2, 'fill' => 2, 'border' => 1, 'align' => 'left'],
	'note'        => ['font' => 3, 'fill' => 0, 'border' => 0, 'align' => 'left'],
];

/**
 * Writes a multi-sheet .xlsx workbook with basic styling (bold titles,
 * bordered grids, shaded header rows, column widths, merged cells) using
 * raw OOXML — same dependency-free approach as the rest of this file.
 *
 * Sheet spec:
 *   [
 *     'name'   => 'List_SUW',
 *     'widths' => [6, 32, ...],          // optional, per-column widths
 *     'merges' => ['A1:H1'],             // optional, merged ranges
 *     'rows'   => [
 *         [ ['v' => 'TEXT', 's' => 'title'], ... ],   // styled cell
 *         [ 'plain value', 123 ],                      // default style
 *     ],
 *   ]
 */
function xlsx_lite_write_workbook(string $path, array $sheets): bool
{
	xlsx_lite_require_zip();

	if ($sheets === []) {
		return false;
	}

	$zip = new ZipArchive();

	if (file_exists($path)) {
		@unlink($path);
	}

	if ($zip->open($path, ZipArchive::CREATE) !== true) {
		return false;
	}

	$contentTypeOverrides = '';
	$workbookSheets = '';
	$workbookRels = '';
	$sheetXmlParts = [];

	foreach (array_values($sheets) as $index => $spec) {
		$sheetNumber = $index + 1;
		$safeName = substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string)($spec['name'] ?? '')), 0, 31) ?: ('Sheet' . $sheetNumber);

		$contentTypeOverrides .= '<Override PartName="/xl/worksheets/sheet' . $sheetNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		$workbookSheets .= '<sheet name="' . htmlspecialchars($safeName, ENT_XML1) . '" sheetId="' . $sheetNumber . '" r:id="rId' . $sheetNumber . '"/>';
		$workbookRels .= '<Relationship Id="rId' . $sheetNumber . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetNumber . '.xml"/>';

		$sheetXmlParts['xl/worksheets/sheet' . $sheetNumber . '.xml'] =
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
			'<sheetFormatPr defaultRowHeight="15"/>' .
			xlsx_lite_sheet_body_xml($spec) .
			'</worksheet>';
	}

	// Write infrastructure files FIRST (OOXML recommended order), then sheets.
	$zip->addFromString('[Content_Types].xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
		'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
		'<Default Extension="xml" ContentType="application/xml"/>' .
		'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
		$contentTypeOverrides .
		'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
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
		$workbookRels .
		'<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
		'</Relationships>'
	);

	$zip->addFromString('xl/workbook.xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
		'<sheets>' . $workbookSheets . '</sheets>' .
		'</workbook>'
	);

	$zip->addFromString('xl/styles.xml', xlsx_lite_styles_xml());

	// Write sheet XML parts last.
	foreach ($sheetXmlParts as $partName => $xmlContent) {
		$zip->addFromString($partName, $xmlContent);
	}

	$zip->close();

	return true;
}

/** Builds the <cols>, <sheetData> and <mergeCells> payload of one worksheet. */
function xlsx_lite_sheet_body_xml(array $spec): string
{
	$xml = '';

	$widths = $spec['widths'] ?? [];

	if ($widths !== []) {
		$xml .= '<cols>';
		foreach (array_values($widths) as $colIndex => $width) {
			$xml .= '<col min="' . ($colIndex + 1) . '" max="' . ($colIndex + 1) . '" width="' . (float)$width . '" customWidth="1"/>';
		}
		$xml .= '</cols>';
	}

	$xml .= '<sheetData>';

	foreach (array_values($spec['rows'] ?? []) as $rowIndex => $row) {
		$excelRow = $rowIndex + 1;
		$xml .= '<row r="' . $excelRow . '">';

		foreach (array_values($row) as $colIndex => $cell) {
			if (!is_array($cell)) {
				$cell = ['v' => $cell];
			}

			$value = $cell['v'] ?? '';
			$styleKey = (string)($cell['s'] ?? 'default');
			$styleIndex = xlsx_lite_style_index($styleKey);
			$cellRef = xlsx_lite_index_to_column_ref($colIndex) . $excelRow;

			if (is_int($value) || is_float($value)) {
				$xml .= '<c r="' . $cellRef . '" s="' . $styleIndex . '"><v>' . xlsx_lite_number_to_string($value) . '</v></c>';
			} else {
				$stringValue = (string)$value;

				if ($stringValue !== '' && !isset($cell['text']) && is_numeric($stringValue)) {
					$xml .= '<c r="' . $cellRef . '" s="' . $styleIndex . '"><v>' . htmlspecialchars($stringValue, ENT_XML1) . '</v></c>';
				} else {
					$xml .= '<c r="' . $cellRef . '" s="' . $styleIndex . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars($stringValue, ENT_XML1) . '</t></is></c>';
				}
			}
		}

		$xml .= '</row>';
	}

	$xml .= '</sheetData>';

	$merges = $spec['merges'] ?? [];

	if ($merges !== []) {
		$xml .= '<mergeCells count="' . count($merges) . '">';
		foreach ($merges as $range) {
			$xml .= '<mergeCell ref="' . htmlspecialchars((string)$range, ENT_XML1) . '"/>';
		}
		$xml .= '</mergeCells>';
	}

	return $xml;
}

/** Resolves a named style key to its zero-based position in cellXfs. */
function xlsx_lite_style_index(string $key): int
{
	$index = array_search($key, array_keys(XLSX_LITE_STYLES), true);

	return $index === false ? 0 : (int)$index;
}

/** Emits the shared styles.xml (fonts, fills, borders, cellXfs). */
function xlsx_lite_styles_xml(): string
{
	$fontsXml =
		'<font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>' .
		'<font><b/><sz val="14"/><color rgb="FF1F3864"/><name val="Calibri"/></font>' .
		'<font><b/><sz val="11"/><color rgb="FF1F3864"/><name val="Calibri"/></font>' .
		'<font><i/><sz val="9"/><color rgb="FF595959"/><name val="Calibri"/></font>';

	$fillsXml =
		'<fill><patternFill patternType="none"/></fill>' .
		'<fill><patternFill patternType="gray125"/></fill>' .
		'<fill><patternFill patternType="solid"><fgColor rgb="FFD9E1F2"/><bgColor indexed="64"/></patternFill></fill>';

	$bordersXml =
		'<border><left/><right/><top/><bottom/><diagonal/></border>' .
		'<border>' .
		'<left style="thin"><color rgb="FF000000"/></left>' .
		'<right style="thin"><color rgb="FF000000"/></right>' .
		'<top style="thin"><color rgb="FF000000"/></top>' .
		'<bottom style="thin"><color rgb="FF000000"/></bottom>' .
		'<diagonal/>' .
		'</border>';

	$cellXfsXml = '';

	foreach (XLSX_LITE_STYLES as $style) {
		$applyAlignment = isset($style['align']) || isset($style['wrap']);
		$alignment = '';

		if ($applyAlignment) {
			$hAlign = $style['align'] ?? 'left';
			$wrap = !empty($style['wrap']) ? ' wrapText="1"' : '';
			$vertical = isset($style['wrap']) ? ' vertical="center"' : '';
			$alignment = '<alignment horizontal="' . $hAlign . '"' . $vertical . $wrap . '/>';
		}

		$cellXfsXml .= '<xf numFmtId="0" fontId="' . $style['font'] . '" fillId="' . $style['fill'] . '" borderId="' . $style['border'] . '" xfId="0"'
			. (($style['font'] || $style['fill'] || $style['border']) ? ' applyFont="1" applyFill="1" applyBorder="1"' : '')
			. ($applyAlignment ? ' applyAlignment="1"' : '')
			. '>' . $alignment . '</xf>';
	}

	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
		'<fonts count="4">' . $fontsXml . '</fonts>' .
		'<fills count="3">' . $fillsXml . '</fills>' .
		'<borders count="2">' . $bordersXml . '</borders>' .
		'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
		'<cellXfs count="' . count(XLSX_LITE_STYLES) . '">' . $cellXfsXml . '</cellXfs>' .
		'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
		'</styleSheet>';
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