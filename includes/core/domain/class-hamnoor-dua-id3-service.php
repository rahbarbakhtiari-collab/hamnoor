<?php
if (!defined('ABSPATH')) { exit; }

/** Text-only ID3v2.3 writer ported from Manahel Mafatih Online 3.3.1. */
final class Hamnoor_Dua_ID3_Service {
    const SITE_NAME = 'هم‌نور';

    public static function sync_attachment($attachment_id, $audio, $content_title = '') {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id || 'attachment' !== get_post_type($attachment_id) || 0 !== strpos((string)get_post_mime_type($attachment_id), 'audio/')) {
            return new WP_Error('hamnoor_dua_audio_attachment_invalid', __('رسانه صوتی معتبر پیدا نشد.', 'hamnoor'));
        }
        $audio = Hamnoor_Dua_Audio_Service::normalize_item($audio);
        $title = trim(wp_strip_all_tags($content_title ?: ($audio['segment_title'] ?: __('صوت دعا', 'hamnoor'))));
        $artist = trim(wp_strip_all_tags($audio['reciter']));
        $label = $artist ? $title . ' — ' . $artist : $title;
        $updated = wp_update_post(array('ID'=>$attachment_id,'post_title'=>$label,'post_excerpt'=>sprintf(__('صوت %s در هم‌نور', 'hamnoor'),$label)), true);
        if (is_wp_error($updated)) { return $updated; }
        update_post_meta($attachment_id, '_hamnoor_dua_audio_reciter', $artist);
        update_post_meta($attachment_id, '_hamnoor_dua_audio_scope', $audio['scope']);
        update_post_meta($attachment_id, '_hamnoor_dua_audio_segment_key', $audio['segment_key']);
        delete_post_meta($attachment_id, '_thumbnail_id');
        $file = get_attached_file($attachment_id);
        if ($file && is_file($file)) {
            $tag = self::tag_file($file, $audio, $title, (string)get_post_mime_type($attachment_id));
            if (is_wp_error($tag)) { return $tag; }
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attachment_id, $file);
            if (is_array($metadata)) { wp_update_attachment_metadata($attachment_id, self::strip_artwork($metadata)); }
        }
        return true;
    }

    public static function tag_file($path, $audio, $title, $mime = '') {
        if (!is_file($path) || !is_writable($path)) { return new WP_Error('hamnoor_id3_file', __('فایل صوتی برای بازنویسی تگ در دسترس نیست.', 'hamnoor')); }
        if ('mp3' !== strtolower(pathinfo($path, PATHINFO_EXTENSION)) && !in_array(strtolower($mime), array('audio/mpeg','audio/mp3'), true)) { return true; }
        $frames = self::text_frame('TIT2',$title) . self::text_frame('TPE1',$audio['reciter']) . self::text_frame('TALB',__('ادعیه هم‌نور','hamnoor')) . self::text_frame('TPUB',self::SITE_NAME) . self::text_frame('TCON',__('دعا و زیارت','hamnoor')) . self::user_text_frame(__('منبع صوت','hamnoor'),$audio['source_page_url']);
        $tag = 'ID3' . chr(3) . chr(0) . chr(0) . self::synchsafe(strlen($frames)) . $frames;
        $in=@fopen($path,'rb'); if(!$in){return new WP_Error('hamnoor_id3_read',__('فایل صوتی خوانده نشد.','hamnoor'));}
        $size=(int)filesize($path);$start=0;$end=$size;$ten=fread($in,10);
        if(10===strlen($ten)&&'ID3'===substr($ten,0,3)){$start=min($size,10+self::decode_synchsafe(substr($ten,6,4))+((ord($ten[5])&0x10)?10:0));}
        if($size>=128){fseek($in,-128,SEEK_END);if('TAG'===fread($in,3)){$end-=128;}}
        $tmp=$path.'.hamnoor-id3-'.wp_generate_password(8,false,false).'.tmp';$out=@fopen($tmp,'wb');if(!$out){fclose($in);return new WP_Error('hamnoor_id3_write',__('فایل موقت تگ ساخته نشد.','hamnoor'));}
        if(fwrite($out,$tag)!==strlen($tag)){fclose($in);fclose($out);@unlink($tmp);return new WP_Error('hamnoor_id3_header',__('نوشتن ID3 کامل نشد.','hamnoor'));}
        fseek($in,$start);$remaining=max(0,$end-$start);while($remaining>0&&!feof($in)){$chunk=fread($in,min(1048576,$remaining));if(false===$chunk||''===$chunk){break;}if(false===fwrite($out,$chunk)){break;}$remaining-=strlen($chunk);}fclose($in);fclose($out);
        if($remaining>0||!@rename($tmp,$path)){@unlink($tmp);return new WP_Error('hamnoor_id3_replace',__('بازنویسی کامل ID3 انجام نشد.','hamnoor'));}clearstatcache(true,$path);return true;
    }

    private static function strip_artwork($meta){foreach((array)$meta as $key=>$value){if(preg_match('/(?:image|cover|artwork|album_art|apic)/i',(string)$key)){unset($meta[$key]);}elseif(is_array($value)){$meta[$key]=self::strip_artwork($value);}}return $meta;}
    private static function frame($id,$body){return $id.pack('N',strlen($body))."\x00\x00".$body;}
    private static function text_frame($id,$text){$text=trim((string)$text);return ''===$text?'':self::frame($id,chr(1).self::utf16($text));}
    private static function user_text_frame($description,$value){$value=trim((string)$value);return ''===$value?'':self::frame('TXXX',chr(1).self::utf16($description)."\x00\x00".self::utf16($value));}
    private static function utf16($text){$encoded=function_exists('mb_convert_encoding')?mb_convert_encoding((string)$text,'UTF-16LE','UTF-8'):(function_exists('iconv')?iconv('UTF-8','UTF-16LE//IGNORE',(string)$text):(string)$text);return "\xFF\xFE".$encoded;}
    private static function synchsafe($n){return chr(($n>>21)&0x7F).chr(($n>>14)&0x7F).chr(($n>>7)&0x7F).chr($n&0x7F);}
    private static function decode_synchsafe($b){return 4===strlen($b)?(ord($b[0])<<21)|(ord($b[1])<<14)|(ord($b[2])<<7)|ord($b[3]):0;}
}
