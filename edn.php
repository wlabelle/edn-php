// Copyright 2012 University of Maryland Diagnostic Imaging Specialists, P.A.

<?php

$edn_tag_handlers = array('inst'=>'__edn_handle_inst',
			  'uuid'=>'__edn_handle_uuid');

function __edn_handle_inst($data)
{
	$match = array();
	if(preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})[Tt]([0-9]{2}):([0-9]{2}):([0-9]{2}(?:\\.[0-9]+)?)(.*)$/', $data, $match)) {
		$date_str = sprintf("%04u-%02u-%02uT%02u:%02u:%09.6F%s",
				    $match[1],$match[2],$match[3],$match[4],$match[5],(float)$match[6],$match[7]);
		return new DateTime($date_str,new DateTimeZone('UTC'));
	} else {
		throw new Exception("Error invalid instant format $data");
	}
}

function __edn_handle_uuid($data)
{
	return $data;
}

function __edn_get_input($str)
{
	return trim($str," \t\f\n\r\v,");
}

function __edn_eat_char($str,$char_count)
{
	return substr($str,$char_count);
}

function __edn_return($value, $rest)
{
	return array('value'=>$value, 'rest'=>$rest);
}

function __edn_read_items($in_str, $term_char)
{
	$str = __edn_get_input($in_str);
	$items = array();
	while($str[0] != $term_char) {
		if($str == '') {
			throw new Exception("Error EOF expecting $term_char");
		}
		$return = __edn_read_string_internal($str);
		$items[] = $return['value'];
		$str = __edn_get_input($return['rest']);

	}
	return __edn_return($items, __edn_eat_char($str,1));
}

function __edn_is_whitespace($ch)
{
	return preg_match('/[\s,]/',$ch);
}

function __edn_is_term_char($ch)
{
	return preg_match("/[\s,\(\)\[\]\{\}]/",$ch);
}

function __edn_eat_comment($input_str)
{
	$i = 0;
	$ch = $input_str[$i];
	while(1) {
		if($ch == '' || $ch == "\n")
			return __edn_eat_char($input_str,$i+1);
		$i++;
		$ch = $input_str[$i];
	}

}

function __edn_read_token($input_str)
{
	$token = '';
	$i = 0;
	$ch = $input_str[$i];
	while(1) {
		if($ch == '' || __edn_is_term_char($ch))
			return __edn_return($token,__edn_eat_char($input_str,$i));
		$token = $token . $ch;
		$i++;
		$ch = $input_str[$i];
	}
}

function __edn_read_number($input_str)
{
	$rtn = __edn_read_token($input_str);
	$number_token = $rtn['value'];

	$match = array();
	if(preg_match('/^([-+]?[0-9]+[0-9]*)$/',$number_token, $match))
		return __edn_return((int)$match[1],$rtn['rest']);
	$match = array();
	if(preg_match('/^([-+]?[0-9]+(\\.[0-9]*)?([eE][-+]?[0-9]+)?)(M)?$/',$number_token, $match))
		return __edn_return((float)$match[1],$rtn['rest']);
	throw new Exception("Error invalid number format $number_token");
}

function __edn_read_symbol($input_str)
{

	$rtn = __edn_read_token($input_str);

	$sym = $rtn['value'];

	switch($sym) {
	case 'nil':
		$value = NULL;
		break;
	case 'true':
		$value = True;
		break;
	case 'false':
		$value = False;
		break;
	default:
		$value = $sym;
	}
	return __edn_return($value,$rtn['rest']);
}

function __edn_read_dispatch($str)
{
	global $edn_tag_handlers;

	switch($str[0]) {
	case '{':
		return __edn_read_items(__edn_eat_char($str,1),"}");
	case '_':
		$rtn = __edn_read_string_internal(__edn_eat_char($str,1));
		return __edn_read_string_internal($rtn['rest']);
	default:
		$tag_rtn = __edn_read_token($str);
		$tag = $tag_rtn['value'];
		$data_rtn = __edn_read_string_internal($tag_rtn['rest']);
		if(array_key_exists($tag,$edn_tag_handlers))
			$value = $edn_tag_handlers[$tag]($data_rtn['value']);
		else
			$value = array('tag'=>$tag, 'data'=> $data_rtn['value']);
		return __edn_return($value,
				    $data_rtn['rest']);
	}
}

function __edn_string_reader($input_str)
{
	$str = '';
	$i = 0;
	$ch = $input_str[$i];
	while($ch != '"') {
		if($ch == '')
			throw new Exception("Error EOF reading string");
		switch($ch) {
		case '\\':
			$i++;
			$ch = $input_str[$i];
			switch($ch) {
			case '':
				throw new Exception("Error EOF reading string");
			case 't':
				$ch = "\t";
				break;
			case 'n':
				$ch = "\n";
				break;
			case 'r':
				$ch = "\r";
				break;
			case "\\":
			case '"':
				break;
			default:
				throw new Exception("Error unknown string escape character \\$ch");
			}
		}
		$str = $str . $ch;
		$i++;
		$ch = $input_str[$i];
	}
	return __edn_return($str,__edn_eat_char($input_str,$i+1));
}

function __edn_read_string_internal($input_str)
{
	$str = __edn_get_input($input_str);
	switch($str[0]) {
	case '"':
		return __edn_string_reader(__edn_eat_char($str,1));
	case '{':
		$return = __edn_read_items(__edn_eat_char($str,1),'}');
		$new_dict = array();
		$values = $return['value'];
		$val_len = count($values);
		if($val_len&1)
			throw new Exception('Error odd number of map pairs');
		for($i = 0; $i < $val_len; $i+=2) {
			$key = $values[$i];
			$val = $values[$i+1];
			$new_dict[$key] = $val;
		}
		return __edn_return($new_dict,$return['rest']);
	case '(':
		return __edn_read_items(__edn_eat_char($str,1),')');
	case '[':
		return __edn_read_items(__edn_eat_char($str,1),']');
	case ':':
		return __edn_read_token(__edn_eat_char($str,1));
	case '#':
		return __edn_read_dispatch(__edn_eat_char($str,1));
	case '\\':
		return __edn_read_token(__edn_eat_char($str,1));
	case '':
		return array('rest'=>'');
	case ';':
		$rest = __edn_eat_comment($str);
		return __edn_read_string_internal($rest);
	case ']':
	case '}':
	case ')':
		throw new Exception("Error unmatched delimiter $str[0]");
	default:
		if(is_numeric($str[0]) or
		   $str[0] == '-' or
		   ($str[0] == '+' and is_numeric($str[1])))
			return __edn_read_number($str);
		return __edn_read_symbol($str);
	}
}

function edn_read_string($input_str)
{
	$rtn = __edn_read_string_internal($input_str);
	return $rtn['value'];
}

function edn_register_handler($tag, $func_str)
{
	global $edn_tag_handlers;
	$edn_tag_handlers[$tag] = $func_str;
}

?>
