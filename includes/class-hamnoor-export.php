<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Export {
    public static function headers($include_contacts = true) {
        $headers = array('شماره سهم', 'شماره پویش/دوره', 'عنوان سهم', 'وضعیت فارسی', 'نام شرکت‌کننده');
        if ($include_contacts) { $headers[] = 'موبایل'; }
        $headers = array_merge($headers, array('زمان رزرو', 'زمان ثبت قرائت'));
        return apply_filters('hamnoor_export_columns', $headers, $include_contacts, Hamnoor_Mobile_Access::current_user_id());
    }

    public static function row_for_part($part, $include_contacts = true) {
        $row = array(
            (int) $part->id,
            isset($part->round_number) ? (int) $part->round_number : 1,
            isset($part->title) ? $part->title : '',
            self::status_label(isset($part->status) ? $part->status : ''),
            isset($part->participant_name) ? $part->participant_name : '',
        );
        if ($include_contacts) {
            $row[] = isset($part->participant_phone) ? $part->participant_phone : '';
        }
        $row[] = Hamnoor_Jalali::to_jalali(isset($part->claimed_at) ? $part->claimed_at : '', true);
        $row[] = Hamnoor_Jalali::to_jalali(isset($part->done_at) ? $part->done_at : '', true);
        $row = apply_filters('hamnoor_participant_export_row', $row, $part, $include_contacts, Hamnoor_Mobile_Access::current_user_id());
        return apply_filters('hamnoor_export_rows', $row, $part, $include_contacts, Hamnoor_Mobile_Access::current_user_id());
    }

    public static function rows_for_khatm($khatm_id, $include_contacts = true) {
        $rows = array(self::headers($include_contacts));
        $offset = 0; $limit = 500;
        do {
            $parts = Hamnoor_DB::get_parts((int) $khatm_id, '', array('limit'=>$limit, 'offset'=>$offset));
            foreach ($parts as $part) { $rows[] = self::row_for_part($part, $include_contacts); }
            $offset += $limit;
        } while (count($parts) === $limit);
        $rows = apply_filters('hamnoor_participant_export_fields', $rows, $khatm_id, Hamnoor_Mobile_Access::current_user_id());
        return apply_filters('hamnoor_report_data', $rows, $khatm_id, Hamnoor_Mobile_Access::current_user_id());
    }

    public static function status_label($status) { return Hamnoor_Helpers::status_label($status, 'part'); }

    public static function owner_export_mode() {
        $settings = Hamnoor_Helpers::settings();
        if (!empty($settings['owner_export_mode'])) { return in_array($settings['owner_export_mode'], array('disabled','limited','full'), true) ? $settings['owner_export_mode'] : 'limited'; }
        return (!empty($settings['allow_owner_contact_export']) && '1' === $settings['allow_owner_contact_export']) ? 'full' : 'limited';
    }

    public static function can_export($khatm_id, $user_id) {
        if (current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms')) { return true; }
        if (!Hamnoor_DB::user_can_manage_khatm($khatm_id, $user_id)) { return false; }
        return 'disabled' !== self::owner_export_mode();
    }

    public static function can_include_contacts($khatm_id, $user_id) {
        if (current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms')) { return true; }
        return 'full' === self::owner_export_mode() && Hamnoor_DB::user_can_manage_khatm($khatm_id, $user_id) && Hamnoor_Mobile_Access::has_sensitive_access((int)$user_id);
    }

    public static function download_csv($khatm_id) {
        $khatm = Hamnoor_DB::get_khatm((int)$khatm_id); if (!$khatm) { wp_die(esc_html__('پویش پیدا نشد.', 'hamnoor')); }
        $user_id = Hamnoor_Mobile_Access::current_user_id(); $include_contacts = self::can_include_contacts($khatm_id, $user_id);
        Hamnoor_DB::log((int)$khatm_id, 0, 0, $user_id, 'export_csv', __('خروجی CSV دریافت شد.', 'hamnoor'));
        do_action('hamnoor_export_downloaded', (int)$khatm_id, (int)$user_id, 'csv');
        self::clean_output_buffers();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hamnoor-khatm-' . (int)$khatm_id . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, array_map(array('Hamnoor_Helpers','csv_safe'), self::headers($include_contacts)));
        $offset = 0; $limit = 500;
        do {
            $parts = Hamnoor_DB::get_parts((int)$khatm_id, '', array('limit'=>$limit, 'offset'=>$offset));
            foreach ($parts as $part) { fputcsv($out, array_map(array('Hamnoor_Helpers','csv_safe'), self::row_for_part($part, $include_contacts))); }
            $offset += $limit;
            if (function_exists('flush')) { flush(); }
        } while (count($parts) === $limit);
        fclose($out); exit;
    }

    public static function download_xlsx($khatm_id) {
        if (!class_exists('ZipArchive')) { self::download_csv($khatm_id); }
        $khatm = Hamnoor_DB::get_khatm((int)$khatm_id); if (!$khatm) { wp_die(esc_html__('پویش پیدا نشد.', 'hamnoor')); }
        $user_id = Hamnoor_Mobile_Access::current_user_id(); $include_contacts = self::can_include_contacts($khatm_id, $user_id);
        Hamnoor_DB::log((int)$khatm_id, 0, 0, $user_id, 'export_xlsx', __('خروجی Excel دریافت شد.', 'hamnoor'));
        do_action('hamnoor_export_downloaded', (int)$khatm_id, (int)$user_id, 'xlsx');

        $tmp = wp_tempnam('hamnoor-export.xlsx');
        $sheet_tmp = wp_tempnam('hamnoor-sheet.xml');
        if (!$tmp || !$sheet_tmp) { self::download_csv($khatm_id); }
        $sheet_handle = fopen($sheet_tmp, 'wb');
        if (!$sheet_handle) { self::download_csv($khatm_id); }

        fwrite($sheet_handle, self::worksheet_open());
        $row_index = 1;
        fwrite($sheet_handle, self::xlsx_row($row_index++, self::headers($include_contacts), true));
        $offset = 0; $limit = 500;
        do {
            $parts = Hamnoor_DB::get_parts((int)$khatm_id, '', array('limit'=>$limit, 'offset'=>$offset));
            foreach ($parts as $part) { fwrite($sheet_handle, self::xlsx_row($row_index++, self::row_for_part($part, $include_contacts), false)); }
            $offset += $limit;
        } while (count($parts) === $limit);
        fwrite($sheet_handle, self::worksheet_close());
        fclose($sheet_handle);

        $zip = new ZipArchive();
        if (true !== $zip->open($tmp, ZipArchive::OVERWRITE)) { wp_delete_file($sheet_tmp); self::download_csv($khatm_id); }
        $zip->addFromString('[Content_Types].xml', self::content_types_xml());
        $zip->addFromString('_rels/.rels', self::root_rels_xml());
        $zip->addFromString('docProps/core.xml', self::core_props_xml());
        $zip->addFromString('docProps/app.xml', self::app_props_xml());
        $zip->addFromString('xl/workbook.xml', self::workbook_xml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbook_rels_xml());
        $zip->addFromString('xl/styles.xml', self::styles_xml());
        $zip->addFile($sheet_tmp, 'xl/worksheets/sheet1.xml');
        if (!$zip->close()) { wp_delete_file($sheet_tmp); self::download_csv($khatm_id); }
        wp_delete_file($sheet_tmp);

        self::clean_output_buffers();
        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="hamnoor-khatm-' . (int)$khatm_id . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        wp_delete_file($tmp);
        exit;
    }

    private static function worksheet_open() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols><col min="1" max="1" width="12" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/><col min="3" max="3" width="35" customWidth="1"/><col min="4" max="4" width="18" customWidth="1"/><col min="5" max="7" width="22" customWidth="1"/><col min="8" max="8" width="45" customWidth="1"/><col min="9" max="10" width="22" customWidth="1"/></cols>'
            . '<sheetData>';
    }

    private static function worksheet_close() { return '</sheetData></worksheet>'; }

    private static function xlsx_row($row_index, $row, $header = false) {
        $xml = '<row r="' . (int) $row_index . '">';
        foreach (array_values($row) as $c => $value) {
            $cell = self::cell_name($c) . (int) $row_index;
            $style = $header ? ' s="1"' : '';
            $value = Hamnoor_Helpers::csv_safe($value);
            $xml .= '<c r="' . self::xml_escape($cell) . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::xml_escape($value) . '</t></is></c>';
        }
        return $xml . '</row>';
    }

    private static function cell_name($index) { $letters=''; $index++; while($index>0){$mod=($index-1)%26; $letters=chr(65+$mod).$letters; $index=(int)(($index-$mod)/26);} return $letters; }

    private static function xml_escape($value) {
        $value = wp_check_invalid_utf8((string) $value);
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private static function clean_output_buffers() {
        while (ob_get_level() > 0) { @ob_end_clean(); }
    }

    private static function content_types_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function root_rels_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function workbook_rels_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function workbook_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<workbookPr defaultThemeVersion="124226"/>'
            . '<sheets><sheet name="Hamnoor" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function styles_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function core_props_xml() {
        $date = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Hamnoor</dc:creator><cp:lastModifiedBy>Hamnoor</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . self::xml_escape($date) . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . self::xml_escape($date) . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function app_props_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Hamnoor</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop><Company></Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>16.0300</AppVersion>'
            . '</Properties>';
    }
}
