<?php 


if (!function_exists('stringToColor')) { 
    function stringToColor($str) {
        $hash = 0;
        
        for ($i = 0; $i < strlen($str); $i++) {
            $hash = ord(substr($str, $i, 1)) + (int)(($hash << 5) - $hash);
        }
        
        $colour = '#';
    
        for ($i = 0; $i < 3; $i++) {
            $value = ($hash >> ($i * 8)) & 0xFF;
            $colour .= substr('00' . base_convert($value, 10, 16), -2);
        }
        return $colour;
    }

    function adjustBrightness($hexCode, $adjustPercent) {
        $hexCode = ltrim($hexCode, '#');
    
        if (strlen($hexCode) == 3) {
            $hexCode = $hexCode[0] . $hexCode[0] . $hexCode[1] . $hexCode[1] . $hexCode[2] . $hexCode[2];
        }
    
        $hexCode = array_map('hexdec', str_split($hexCode, 2));
    
        foreach ($hexCode as & $color) {
            $adjustableLimit = $adjustPercent < 0 ? $color : 255 - $color;
            $adjustAmount = ceil($adjustableLimit * $adjustPercent);
    
            $color = str_pad(dechex($color + $adjustAmount), 2, '0', STR_PAD_LEFT);
        }
    
        return '#' . implode($hexCode);
    }
}  
