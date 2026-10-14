<?php
/**
 * SimpleXLSXGen - Simple Excel XLSX Writer
 * Creates Excel 2007+ (.xlsx) files
 * 
 * This is a simplified version that can create basic Excel files
 * For full functionality, consider using PhpSpreadsheet with Composer
 */

if (!class_exists('SimpleXLSXGen')) {
class SimpleXLSXGen {
    
    private $sheets = [];
    private $currentSheet = 0;
    
    /**
     * Create a new instance
     */
    public function __construct() {
        $this->sheets = [];
        $this->currentSheet = 0;
    }
    
    /**
     * Create from array data
     * @param array $data 2D array of data
     * @return SimpleXLSXGen
     */
    public static function fromArray($data, $sheetName = 'Sheet1') {
        $xlsx = new self();
        $xlsx->addSheet($data, $sheetName);
        return $xlsx;
    }
    
    /**
     * Add a sheet
     * @param array $data 2D array of data
     * @param string $name Sheet name
     */
    public function addSheet($data, $name = null) {
        if ($name === null) {
            $name = 'Sheet' . (count($this->sheets) + 1);
        }
        $this->sheets[] = [
            'name' => $name,
            'data' => $data
        ];
        return $this;
    }
    
    /**
     * Save to file
     * @param string $filename Output filename
     * @return bool
     */
    public function saveAs($filename) {
        $zipContent = $this->generate();
        if ($zipContent === false) {
            return false;
        }
        return file_put_contents($filename, $zipContent) !== false;
    }
    
    /**
     * Download as file
     * @param string $filename Download filename
     */
    public function downloadAs($filename) {
        $zipContent = $this->generate();
        if ($zipContent === false) {
            return false;
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($zipContent));
        header('Cache-Control: max-age=0');
        
        echo $zipContent;
        return true;
    }
    
    /**
     * Get as string
     * @return string|false
     */
    public function toString() {
        return $this->generate();
    }
    
    /**
     * Generate the XLSX file content
     * @return string|false
     */
    private function generate() {
        if (empty($this->sheets)) {
            return false;
        }
        
        $zip = new ZipArchive();
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
        
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        
        // Add required structure
        $zip->addFromString('[Content_Types].xml', $this->getContentTypes());
        $zip->addFromString('_rels/.rels', $this->getRels());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->getWorkbookRels());
        $zip->addFromString('xl/workbook.xml', $this->getWorkbook());
        $zip->addFromString('xl/styles.xml', $this->getStyles());
        
        // Add shared strings if needed
        $sharedStrings = $this->collectSharedStrings();
        if (!empty($sharedStrings)) {
            $zip->addFromString('xl/sharedStrings.xml', $this->getSharedStrings($sharedStrings));
        }
        
        // Add sheets
        foreach ($this->sheets as $index => $sheet) {
            $sheetNum = $index + 1;
            $zip->addFromString('xl/worksheets/sheet' . $sheetNum . '.xml', 
                $this->getSheet($sheet['data'], $sharedStrings));
        }
        
        $zip->close();
        
        $content = file_get_contents($tmpFile);
        unlink($tmpFile);
        
        return $content;
    }
    
    /**
     * Collect all unique strings for shared strings table
     */
    private function collectSharedStrings() {
        $strings = [];
        $stringMap = [];
        
        foreach ($this->sheets as $sheet) {
            foreach ($sheet['data'] as $row) {
                foreach ($row as $cell) {
                    if ($cell !== null && $cell !== '') {
                        // Convert all values to string for consistent handling
                        if (is_bool($cell)) {
                            $cellValue = $cell ? '1' : '0';
                        } else {
                            $cellValue = (string)$cell;
                        }
                        
                        if (!isset($stringMap[$cellValue])) {
                            $stringMap[$cellValue] = count($strings);
                            $strings[] = $cellValue;
                        }
                    }
                }
            }
        }
        
        return $strings;
    }
    
    /**
     * Get [Content_Types].xml
     */
    private function getContentTypes() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
    ' . $this->getSheetContentTypes() . '
</Types>';
    }
    
    /**
     * Get sheet content types
     */
    private function getSheetContentTypes() {
        $xml = '';
        for ($i = 1; $i <= count($this->sheets); $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $xml;
    }
    
    /**
     * Get _rels/.rels
     */
    private function getRels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    }
    
    /**
     * Get xl/_rels/workbook.xml.rels
     */
    private function getWorkbookRels() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        
        for ($i = 1; $i <= count($this->sheets); $i++) {
            $xml .= '<Relationship Id="rId' . ($i + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        
        $xml .= '</Relationships>';
        return $xml;
    }
    
    /**
     * Get xl/workbook.xml
     */
    private function getWorkbook() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>';
        
        foreach ($this->sheets as $index => $sheet) {
            $sheetId = $index + 1;
            $rId = 'rId' . ($index + 3);
            $xml .= '<sheet name="' . htmlspecialchars($sheet['name']) . '" sheetId="' . $sheetId . '" r:id="' . $rId . '"/>';
        }
        
        $xml .= '</sheets>
</workbook>';
        
        return $xml;
    }
    
    /**
     * Get xl/styles.xml (minimal styles)
     */
    private function getStyles() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="1">
        <font>
            <sz val="11"/>
            <name val="Calibri"/>
        </font>
    </fonts>
    <fills count="2">
        <fill>
            <patternFill patternType="none"/>
        </fill>
        <fill>
            <patternFill patternType="gray125"/>
        </fill>
    </fills>
    <borders count="1">
        <border>
            <left/>
            <right/>
            <top/>
            <bottom/>
            <diagonal/>
        </border>
    </borders>
    <cellXfs count="1">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
    </cellXfs>
</styleSheet>';
    }
    
    /**
     * Get xl/sharedStrings.xml
     */
    private function getSharedStrings($strings) {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
        
        foreach ($strings as $string) {
            $xml .= '<si><t>' . htmlspecialchars($string, ENT_XML1) . '</t></si>';
        }
        
        $xml .= '</sst>';
        return $xml;
    }
    
    /**
     * Get sheet XML
     */
    private function getSheet($data, $sharedStrings) {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>';
        
        $stringMap = array_flip($sharedStrings);
        
        foreach ($data as $rowIndex => $row) {
            $rowNum = $rowIndex + 1;
            $xml .= '<row r="' . $rowNum . '">';
            
            foreach ($row as $colIndex => $cell) {
                $colRef = $this->getColumnLetter($colIndex) . $rowNum;
                
                if ($cell === null || $cell === '') {
                    continue;
                }
                
                // Numeri veri (int/float PHP) come celle numeriche: chi vuole
                // un numero in Excel fa il cast prima (vedi AiExport); le
                // stringhe restano testo per non perdere zeri iniziali.
                if (is_int($cell) || is_float($cell)) {
                    $xml .= '<c r="' . $colRef . '"><v>' . $cell . '</v></c>';
                    continue;
                }

                // Always treat as string to preserve formatting
                if (is_bool($cell)) {
                    // Boolean - convert to string representation
                    $cellValue = $cell ? '1' : '0';
                } else {
                    // Convert everything to string
                    $cellValue = (string)$cell;
                }
                
                // Use shared strings for all values
                if (isset($stringMap[$cellValue])) {
                    $xml .= '<c r="' . $colRef . '" t="s"><v>' . $stringMap[$cellValue] . '</v></c>';
                } else {
                    // Inline string as fallback
                    $xml .= '<c r="' . $colRef . '" t="inlineStr"><is><t>' . htmlspecialchars($cellValue, ENT_XML1) . '</t></is></c>';
                }
            }
            
            $xml .= '</row>';
        }
        
        $xml .= '</sheetData>
</worksheet>';
        
        return $xml;
    }
    
    /**
     * Convert column index to letter (0 -> A, 1 -> B, etc.)
     */
    private function getColumnLetter($index) {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intval($index / 26) - 1;
        }
        return $letter;
    }
}
}
?>