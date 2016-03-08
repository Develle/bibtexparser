<?php

namespace AudioLabs\BibtexParser;

use Papr\WSHal;

class BibtexParser
{
    static function parse_file($filename) {
        return self::parse_lines(file($filename));
    }

    static function parse_string($data) {
        return self::parse_lines(preg_split('/\n/', $data));
    }

    static function parse_lines($lines) {
        $items = array();
        $count = -1;

        if (!$lines)
            return;

	$bom = pack('H*','EFBBBF');
	$lines = preg_replace("/^$bom/", '', $lines);
	
        foreach($lines as $number => $line) {
            $line = trim($line);

            // empty line
            if (!strlen($line))
                continue;

            // some funny comment string
            if (strpos(strtolower($line),'@string')!==false)
                continue;

            // pybliographer comments
            if (strpos(strtolower($line),'@comment')!==false)
                continue;

            // normal TeX style comment
            if ($line[0] == "%" || $line[0] == "*")
                continue;

            // begins with @, for example @inproceedings{...}
            if ($line[0] == "@") {
                $count++;
                $handle="";
                $value="";
                $data="";
                $start=strpos($line,'@');
                $end=strpos($line,'{');
                $items[$count] = array();
                $items[$count]['raw'] = "";
                $items[$count]['type'] = ucfirst(trim(substr($line, 1,$end-1)));
                $items[$count]['reference'] = trim(substr($line, $end+1), ', ');
                $items[$count]['lines'] = array('start' => $number + 1, 'end' => $number + 1);
            }
	    elseif ($count < 0) {
	      continue;
	    }
            // contains =, for example authors = {...}
            elseif (substr_count($line, '=') > 0) {
                $start = strpos($line,'=');
                $handle = strtolower(trim(substr($line,0,$start)));
                $data = trim(substr($line,$start+1));

                if($handle == 'pages') {
                    preg_match('%(\d+)\s*\-+\s*(\d+)%', $data, $matches);
                   if(count($matches) > 2)
                       $value =  $matches[1] . '-' . $matches[2];
                    else
                        $value = $data;
                }
                elseif($handle == 'author') {
                    $value = explode(' and ', $data);

                }
		elseif($handle == 'journal') {
		  $value = self::cleanup($data);
		}
		elseif($handle == 'month') {
		  switch(substr(self::cleanup($data),0,3)){
		  case "Jan": $value = '01'; break;
		  case "Feb": $value = '02'; break;
		  case "Mar": $value = '03'; break;
		  case "Apr": $value = '04'; break;
		  case "May": $value = '05'; break;
		  case "Jun": $value = '06'; break;
		  case "Jul": $value = '07'; break;
		  case "Aug": $value = '08'; break;
		  case "Sep": $value = '09'; break;
		  case "Oct": $value = '10'; break;
		  case "Nov": $value = '11'; break;
		  case "Dec": $value = '12'; break;
		  default: $value = ''; break;
		  }
		}
		elseif($handle == 'hal_id') {
		  $items[$count]['entry'] = self::parse_type(self::cleanup($data));
                  $value = $data;
		}
		elseif($handle == 'language') {
		  $value = self::parse_language(self::cleanup($data));
		}
                else {
		  $value = $data;
                }
            }
	    
            // neither a new block nor a new field: a following line of a multiline field
            else {
	      if(!is_array($value)) {
                    $value.= ' ' . $line;
                }
            }

	    $items[$count]['raw'] .= $line . "\n";

            if($value != "") {
                $items[$count][$handle] = self::cleanup($value);
            }
            if(count($items) > 0) {
                $items[$count]['lines']['end'] = $number + 1;
            }
        }
        return $items;
    }

    static function cleanup($value) {
        // call cleanup() recursively if passed an array (authors or pages).
        if(is_array($value)) {
            return array_map(array('\AudioLabs\BibtexParser\BibtexParser', 'cleanup'), $value);
        }

	$value=preg_replace("/(^\")/","",$value);
	$value=preg_replace("/(\",$)/","",$value);
        // replace a bunch of LaTeX stuff
	$search  = array("\^e","\'E",'\v s',"\'o","\'\i","\`a","\c c", '\"\i', "\'e", '\"a', '\"A', '\"o', '\"O', '\"u', '\U"', '\ss', '\`e', '\´e', '\url{', '{', '}', '--',      '\"', "\'", '`', '\textbackslash');
        $replace = array('ê','É'  ,'š'   ,'ó'   ,'í'    ,'à'  ,'ç'   , 'ï'   , 'é'  , 'ä',  'Ä',   'ö',   'Ö',   'ü',   'Ü',   'ß',   'è',   'é',   '',      '',  '',  '&mdash;', ' ',  ' ',  ' ', '\\');
        $value=str_replace($search,$replace,$value);
        $value=rtrim($value, '}, ');
	if(!mb_check_encoding($value, 'UTF-8')) $value = utf8_encode($value); 
        return trim($value);
    }

  static function parse_type($hal_id){
      $hal = file_get_contents("http://api.archives-ouvertes.fr/search/?wt=json&q=halId_s:".$hal_id."&q=version_i:1&fl=*");
      $hal = json_decode($hal);
      $hal = self::objectToArray($hal);
      
      if (isset($hal['response']['docs'][0])) {
          $publi = $hal['response']['docs'][0];
          switch($publi['docType_s']){
              case 'ART':
                  if( $publi['audience_s'] == 2 ) 
                      return 'ACLI';
                  elseif( $publi['audience_s'] == 3 )
                      return 'ACLN';
                  else
                      return 'ACLO';
                  break;
                  
              case 'OUV':    return 'OSB';    break;
              case 'COUV':   return 'OSC';    break;
              case 'DOUV':   return 'CONFP';  break;
              case 'HDR':    return 'THH';    break;
              case 'THESE':  return 'THP';    break;
              case 'PATENT': return 'BLL';    break;
              case 'COMM':
                  if( $publi['proceedings_s'] == 1 && $publi['audience_s'] == 2 ) 
                      return 'CONFIA';
                  elseif( $publi['proceedings_s'] == 1 && $publi['audience_s'] == 3 )
                      return 'CONFNA';
                  elseif( $publi['proceedings_s'] == 1 )
                      return 'CONFNA';
                  elseif( $publi['proceedings_s'] == 0 && $publi['audience_s'] == 2 ) 
                      return 'CONFIS';
                  elseif( $publi['proceedings_s'] == 0 && $publi['audience_s'] == 3 )
                      return 'CONFN';
                  else
                      return 'CONFNA';
                  break;
          }
          
          return 'AP';
      }
 
      return false;
    }

    static function parse_language($language){
      if($language == 'Anglais')
	return 'en';
      else
	return 'fr';
    }

    static function objectToArray( $object ) {
	if( !is_object( $object ) && !is_array( $object ) ) {
            return $object;
	}
	if( is_object( $object ) ) {
            $object = get_object_vars( $object );
	}
	return array_map( 'self::objectToArray', $object );
    }

  
}
