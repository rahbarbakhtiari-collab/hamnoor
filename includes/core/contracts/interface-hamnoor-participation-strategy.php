<?php
if (!defined('ABSPATH')) { exit; }

interface Hamnoor_Participation_Strategy {
    public function key();
    public function validate_campaign($campaign);
    public function progress($campaign);
    public function is_complete($campaign);
}
