<?php
/**
 * SimpleXLSX - Simple Excel XLSX Reader
 * Reads Excel 2007+ (.xlsx) files
 * 
 * This is a simplified version that can read basic Excel files
 * For full functionality, consider using PhpSpreadsheet with Composer
 */

if (!class_exists('SimpleXLSX')) {
class SimpleXLSX {
    
    private $zip;
    private $sharedStrings = [];
    private $sheets = [];
    private $errors = [];
    
    /**
     * Parse an XLSX file
     * @param string $filename Path to the XLSX file
     * @return SimpleXLSX|false
     */
    public static function parse($filename) {
        $xlsx = new self();
        if (!$xlsx->loadFile($filename)) {
            return false;
        }
        return $xlsx;
    }
    
    /**
     * Parse XLSX from string data
     * @param string $data XLSX file content
     * @return SimpleXLSX|false
     */
    public static function parseData($data) {
        $xlsx = new self();
        if (!$xlsx->loadData($data)) {
            return false;
        }
        return $xlsx;
    }
    
    /**
     * Load XLSX file
     */
    private function loadFile($filename) {
        if (!file_exists($filename)) {
            $this->errors[] = 'File not found: ' . $filename;
            return false;
        }
        
        $this->zip = new ZipArchive();
        if ($this->zip->open($filename) !== true) {
            $this->errors[] = 'Failed to open ZIP archive';
            return false;
        }
        
        return $this->parseXLSXStructure();
    }
    
    /**
     * Load XLSX from data string
     */
    private function loadData($data) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmpFile, $data);
        
        $this->zip = new ZipArchive();
        if ($this->zip->open($tmpFile) !== true) {
            unlink($tmpFile);
            $this->errors[] = 'Failed to open ZIP archive from data';
            return false;
        }
        
        $result = $this->parseXLSXStructure();
        unlink($tmpFile);
        return $result;
    }
    
    /**
     * Parse the XLSX structure
     */
    private function parseXLSXStructure() {
        // Load shared strings
        $this->loadSharedStrings();
        
        // Load worksheets
        $this->loadWorksheets();
        
        $this->zip->close();
        
        return !empty($this->sheets);
    }
    
    /**
     * Load shared strings from the XLSX
     */
    private function loadSharedStrings() {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return; // No shared strings
        }
        
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        
        $strings = $xpath->query('//s:si');
        foreach ($strings as $string) {
            $textNodes = $xpath->query('.//s:t', $string);
            $text = '';
            foreach ($textNodes as $textNode) {
                $text .= $textNode->nodeValue;
            }
            $this->sharedStrings[] = $text;
        }
    }
    
    /**
     * Load worksheets from the XLSX
     */
    private function loadWorksheets() {
        // Get workbook relationships to find sheets
        $wbRels = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wbRels === false) {
            $this->errors[] = 'Cannot find workbook relationships';
            return;
        }
        
        $dom = new DOMDocument();
        $dom->loadXML($wbRels);
        
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        
        $sheets = $xpath->query('//r:Relationship[@Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"]');
        
        foreach ($sheets as $sheet) {
            $sheetPath = 'xl/' . $sheet->getAttribute('Target');
            $sheetId = $sheet->getAttribute('Id');
            
            $sheetXml = $this->zip->getFromName($sheetPath);
            if ($sheetXml !== false) {
                $this->parseSheet($sheetXml, $sheetId);
            }
        }
    }
    
    /**
     * Parse a single worksheet
     */
    private function parseSheet($xml, $sheetId) {
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        
        $rows = [];
        $rowNodes = $xpath->query('//s:row');
        
        foreach ($rowNodes as $rowNode) {
            $rowNum = (int)$rowNode->getAttribute('r');
            $row = [];
            
            $cells = $xpath->query('s:c', $rowNode);
            foreach ($cells as $cell) {
                $cellRef = $cell->getAttribute('r');
                $colNum = $this->getColumnIndex($cellRef);
                $cellType = $cell->getAttribute('t');
                
                $value = '';
                $valueNode = $xpath->query('s:v', $cell)->item(0);
                
                if ($valueNode) {
                    $value = $valueNode->nodeValue;
                    
                    // Handle different cell types
                    if ($cellType === 's') {
                        // Shared string
                        $value = $this->sharedStrings[(int)$value] ?? '';
                    } elseif ($cellType === 'b') {
                        // Boolean
                        $value = $value === '1' ? 'TRUE' : 'FALSE';
                    } elseif ($cellType === 'inlineStr') {
                        // Inline string
                        $isNode = $xpath->query('s:is/s:t', $cell)->item(0);
                        if ($isNode) {
                            $value = $isNode->nodeValue;
                        }
                    }
                    // Numbers and dates are returned as-is
                }
                
                // Ensure array has enough elements
                while (count($row) < $colNum) {
                    $row[] = '';
                }
                $row[$colNum] = $value;
            }
            
            // Ensure row has consistent column count
            if (!empty($row)) {
                $rows[$rowNum - 1] = $row; // 0-indexed
            }
        }
        
        // Fill missing rows
        $maxRow = !empty($rows) ? max(array_keys($rows)) : 0;
        for ($i = 0; $i <= $maxRow; $i++) {
            if (!isset($rows[$i])) {
                $rows[$i] = [];
            }
        }
        
        ksort($rows);
        $this->sheets[] = array_values($rows);
    }
    
    /**
     * Get column index from cell reference (e.g., "A1" -> 0, "B1" -> 1)
     */
    private function getColumnIndex($cellRef) {
        $col = preg_replace('/[0-9]/', '', $cellRef);
        $colNum = 0;
        $pow = 0;
        
        for ($i = strlen($col) - 1; $i >= 0; $i--) {
            $colNum += (ord($col[$i]) - ord('A') + 1) * pow(26, $pow);
            $pow++;
        }
        
        return $colNum - 1; // 0-indexed
    }
    
    /**
     * Get all rows from the first sheet
     */
    public function rows() {
        if (empty($this->sheets)) {
            return [];
        }
        return $this->sheets[0];
    }
    
    /**
     * Get rows from a specific sheet
     */
    public function rowsEx($sheetIndex = 0) {
        if (!isset($this->sheets[$sheetIndex])) {
            return [];
        }
        return $this->sheets[$sheetIndex];
    }
    
    /**
     * Get all sheets
     */
    public function sheets() {
        return $this->sheets;
    }
    
    /**
     * Get errors
     */
    public function errors() {
        return $this->errors;
    }
    
    /**
     * Convert sheet to CSV format
     */
    public function toCSV($sheetIndex = 0) {
        $rows = $this->rowsEx($sheetIndex);
        $csv = '';
        
        foreach ($rows as $row) {
            $csvRow = [];
            foreach ($row as $cell) {
                // Escape quotes and wrap in quotes if needed
                if (strpos($cell, ',') !== false || strpos($cell, '"') !== false || strpos($cell, "\n") !== false) {
                    $cell = '"' . str_replace('"', '""', $cell) . '"';
                }
                $csvRow[] = $cell;
            }
            $csv .= implode(',', $csvRow) . "\n";
        }
        
        return $csv;
    }
}
}
?>