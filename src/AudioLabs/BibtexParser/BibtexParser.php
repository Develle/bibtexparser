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
		  switch(self::cleanup($data)){
		  case "Jan": $value = '1'; break;
		  case "Feb": $value = '2'; break;
		  case "Mar": $value = '3'; break;
		  case "Apr": $value = '4'; break;
		  case "May": $value = '5'; break;
		  case "Jun": $value = '6'; break;
		  case "Jul": $value = '7'; break;
		  case "Aug": $value = '8'; break;
		  case "Sep": $value = '9'; break;
		  case "Oct": $value = '10'; break;
		  case "Nov": $value = '11'; break;
		  case "Dec": $value = '12'; break;
		  }
		}
		elseif($handle == 'hal_id') {
		  $items[$count]['entry'] = self::parse_type(self::cleanup($data));
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

        // replace a bunch of LaTeX stuff
	$search  = array("\^e","\'E",'\v s',"\'o","\'\i","\`a","\c c", '\"\i', "\'e", '\"a', '\"A', '\"o', '\"O', '\"u', '\U"', '\ss', '\`e', '\´e', '\url{', '{', '}', '--',      '\"', "\'", '`', '\textbackslash');
        $replace = array('ê','É'  ,'š'   ,'ó'   ,'í'    ,'à'  ,'ç'   , 'ï'   , 'é'  , 'ä',  'Ä',   'ö',   'Ö',   'ü',   'Ü',   'ß',   'è',   'é',   '',      '',  '',  '&mdash;', ' ',  ' ',  ' ', '\\');
        $value=str_replace($search,$replace,$value);
        $value=rtrim($value, '}, ');
	if(!mb_check_encoding($value, 'UTF-8')) $value = utf8_encode($value); 
        return trim($value);
    }

  static function parse_type($hal_id){
      $args = array('identifiant' => $hal_id, 'version' => 1);
      $audience = '';

      try {
	$soapclient = new \SoapClient("http://hal.archives-ouvertes.fr/ws/search.php?wsdl", array('trace'=>1));
	$return = $soapclient->getArticleMetadata($args);
      } catch ( \Exception $e ) {
	echo "Exception type: ".$hal_id." / ".$e->getMessage()."<br>";
	return false;
      }
      
      if ( is_soap_fault($return) ) {
	echo "SOAP Fault : (faultcode: {$return->faultcode}, faultstring: {$return->faultstring})<br/>";
      } else {
	foreach($return->getArticleMetadataResult->metaSimple as $metadata){
	  if( $metadata->metaName == 'audience' )
	    $audience = $metadata->metaValue;
	  if( $metadata->metaName == 'typePubli' )
	    $type = $metadata->metaCode;
	}
      }
      switch($type){
      case 'ART_ACL':
	if( $audience == 'internationale' ) 
	  return 'ACLI';
	elseif( $audience == 'nationale' )
	  return 'ACLN';
	else
	  return 'ACLO';
	break;

      case 'ART_SCL':   return 'ACLO';   break;
      case 'OUVS':      return 'OSB';    break;
      case 'COVS':      return 'OSC';    break;
      case 'DOUV':      return 'CONFP';  break;
      case 'HDR':       return 'THH';    break;
      case 'REPORT':    return 'RPT';    break;
      case 'THESE':     return 'THP';    break;
      case 'CONF_INV':  return 'CONFI';  break;
      case 'PATENT':    return 'BLL';    break;
      case 'OTHER':     return 'AP';     break;

      case 'COMM_ACT':
	if( $audience == 'internationale' ) 
	  return 'CONFIA';
	elseif( $audience == 'nationale' )
	  return 'CONFNA';
	else
	  return 'CONFNA';
	break;
	
      case 'COMM_SACT':
	if( $audience == 'internationale' ) 
	  return 'CONFIS';
	elseif( $audience == 'nationale' )
	  return 'CONFN';
	else
	  return 'CONFN';
	break;

      case 'CONF_ACT':
	if( $audience == 'internationale' ) 
	  return 'CONFIA';
	elseif( $audience == 'nationale' )
	  return 'CONFNA';
	break;
	
      case 'CONF_SCT':
	if( $audience == 'internationale' ) 
	  return 'CONFIS';
	elseif( $audience == 'nationale' )
	  return 'CONFN';
	break;
      }
    }

    static function parse_language($language){
      if($language == 'Anglais')
	return 'en';
      else
	return 'fr';
    }  
}
