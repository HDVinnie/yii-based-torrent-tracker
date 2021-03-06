<?php
class StringHelper {

	static function cleanStr ( $str ) {
		$str = rawurldecode(urldecode($str));
		$str = str_replace(array(
		                        '/',
		                        '\\',
		                        "\n",
		                        "\r",
		                        '<',
		                        '>',
		                        '[',
		                        ']',
		                        '(',
		                        ')'
		                   ),
			' ',
			$str);
		$str = str_replace('  ', ' ', $str);
		//$str = htmlentities($str, ENT_QUOTES, 'UTF-8');
		$str = trim($str);
		return $str;
	}

	static function decodeStr ( $str ) {
		$str = str_replace(array(
		                        '/',
		                        '\\'
		                   ),
			' ',
			$str);
		$str = str_replace('  ', ' ', $str);
		//$str = html_entity_decode($str, ENT_QUOTES, 'UTF-8');
		return $str;
	}

	static function cutStr ( $str, $n = 500, $end_char = '&#8230;' ) {
		$Tidy = new Tidy();

		if ( $pos = strpos($str, '<cut />') ) {
			$str = mb_substr($str, 0, $pos);
			$str = $Tidy->repairString($str, array('clean' => true, 'show-body-only'=>true,), 'utf8');
			return $str . $end_char;
		}
		if ( mb_strlen($str) < $n ) {
			return $str;
		}

		$str = preg_replace("/\s+/",
			' ',
			str_replace(array(
			                 "\r\n",
			                 "\r",
			                 "\n"
			            ),
				' ',
				$str));

		if ( mb_strlen($str) <= $n ) {
			return $str;
		}

		$out = "";
		foreach ( explode(' ', trim($str)) as $val ) {
			$out .= $val . ' ';

			if ( mb_strlen($out) >= $n ) {
				$out = trim($out);
				$out = $Tidy->repairString($out, array('clean' => true, 'show-body-only'=>true,), 'utf8');
				return (mb_strlen($out) == mb_strlen($str)) ? $out : $out . $end_char;
			}
		}
	}

	static function ellipsizeStr ( $str, $max_length, $position = 1, $ellipsis = '&hellip;' ) {
		// Strip tags
		$str = trim(strip_tags($str));

		// Is the string long enough to ellipsize?
		if ( mb_strlen($str) <= $max_length ) {
			return $str;
		}

		$beg = mb_substr($str, 0, floor($max_length * $position));

		$position = ($position > 1) ? 1 : $position;

		if ( $position === 1 ) {
			$end = mb_substr($str, 0, -($max_length - mb_strlen($beg)));
		}
		else {
			$end = mb_substr($str, -($max_length - mb_strlen($beg)));
		}

		return $beg . $ellipsis . $end;
	}
}
