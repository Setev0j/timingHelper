<?php
//функция преобразования краткого формата единиц измерения объема данных в байты
function returnBytes($mixValue) {
	if (is_numeric($mixValue)) return intval($mixValue); //если там и так только число - возвращаем без доп.обработки
    $mixValue = trim($mixValue);
    $sLastCharacter = strtolower($mixValue[strlen($mixValue)-1]);
	if (!in_array($sLastCharacter, array('g', 'm', 'k'))) return $mixValue; //если последний символ не попадает под известные сокращения - возвращаем, как есть
	$mixValue = substr($mixValue, 0, -1);
    switch($sLastCharacter) {
        case 'g': $mixValue *= 1024; // The 'G' modifier is available since PHP 5.1.0
        case 'm': $mixValue *= 1024;
        case 'k': $mixValue *= 1024;
		break;
    }
    return $mixValue;
}

//смотрим, открыта ли сессия
function isSessionStarted() {
    if ( php_sapi_name() !== 'cli' ) {
        if (version_compare(phpversion(), '5.4.0', '>=')) {
            return session_status() === PHP_SESSION_ACTIVE ? true : false;
        } else {
            return session_id() === '' ? false : true;
        }
    }
    return false;
}


//Вспомогательные функции для обработки JSON'a и результатов его парса

//функция "сжимания" результирующего массива за счет удаления значений по умолчанию
//на вход подается массив с данными и массив со значениями по умолчанию
function compactDefault($arInput, $arDefaults) {
	$arOutput = $arInput;
	foreach ($arDefaults as $key => $mixDefaultValue) { //проходим по всем значениям по умолчанию
		if (array_key_exists($key, $arOutput)) { //если нам есть, что с чем сравнивать
			if (is_array($mixDefaultValue)) { //если значение по умолчанию является массивом
				if (is_array($arOutput[$key])) { //если в результирующей выборке там тоже массив
					$arTmp = compactDefault($arOutput[$key], $mixDefaultValue); //пробуем пожать этот массив
					if (is_array($arTmp) && count($arTmp) == 0) unset($arOutput[$key]); //если мы ужались до пустого массива - удаляем его из выборки нафик
				} //если в результирующей выборке стоит что-то еще - игнорируем
			} else { //если это не массив, а простой тип - 
				if ($arOutput[$key] == $mixDefaultValue) unset($arOutput[$key]);
			}
		} //если такого ключа в выборке нет - игнорируем
	} //конец прохода по всем значениям по умолчанию
	if (count($arOutput) == 0) return null;
	return $arOutput;
}

//обертка для правильного вызова compactDefault() в callback'е
function compactDefaultMulti(&$arInput, $mixKey, $arDefaults) {
	$arInput = compactDefault($arInput, $arDefaults);
}

//функция "сжимания" результирующего массива за счет удаления значений по умолчанию
//на вход подается массив с данными и массив со значениями по умолчанию
function extractDefault($arInput, $arDefaults) {
	if (is_null($arInput) || (is_array($arInput) && count($arInput) == 0)) return $arDefaults; //если результирующий массив ужат до null или пустого массива - возвращаем вместо него массив значений по умолчанию
	$arOutput = $arInput;
	foreach ($arDefaults as $key => $mixDefaultValue) { //проходим по всем значениям по умолчанию
		if (!array_key_exists($key, $arOutput)) { //если такого ключа нет в результирующем массиве
			$arOutput[$key] = (is_null($mixDefaultValue)) ? "": $mixDefaultValue; //ставим значение по умолчанию
		} else { //если ключ в результ. массиве найден
			if (is_array($arOutput[$key]) && is_array($mixDefaultValue)) $arOutput[$key] = extractDefault($arOutput[$key], $mixDefaultValue); //если там массив - рекурсивно добавляем значения по умолчанию
		}
	} //конец прохода по всем значениям по умолчанию
	if (count($arOutput) == 0) return null; //очень маловероятная штука
	return $arOutput; //как правило отдается заполненный массив
}

//обертка для правильного вызова compactDefault() в callback'е
function extractDefaultMulti(&$arInput, $mixKey, $arDefaults) {
	$arInput = extractDefault($arInput, $arDefaults);
}

//функция отфильтровывания лишнего из результирующего массива
//передаются на вход массив с данными и массив с ключами, значения которых надо сохранить
//$arKeys = массив вида array("KEY TO OUTPUT" => "KEY FROM INPUT")
function arrayFilterResult($arData, $arKeys) {
	$arResult = array(); //будущий результирующий массив
	$arTmpKeys = array_keys($arData);
	if (is_array($arTmpKeys) && count($arTmpKeys) > 0) { //что-то делаем только если у нас непустой массив на входе
		if (is_numeric($arTmpKeys[0])) { //если на вход подали массив массивов (проверка примитивная, возможно, надо будет переделать)
			for($i=0; $i<count($arData); $i++) { //проходим по всем элементам массива
				if (is_array($arData[$i])) 	$arData[$i] = arrayFilterResult($arData[$i], $arKeys); //рекурсивно вызываем себя
			}
		} else { //если массив ассоциативный
			foreach($arKeys as $outPutKey => $inputKey) { //проходим по всм ключам, значения которых надо сохранить
				if (is_numeric($outPutKey)) $iOutputKey = $inputKey; //если ключ выходящего массива не задан явно - используем в качестве него значение
				else $iOutputKey = $outPutKey;
				
				if (array_key_exists($inputKey, $arData)) $arResult[$iOutputKey] = $arData[$inputKey]; //если инфа найдена - заносим в результирующий массив
				unset($iOutputKey);
			}
		}
		if (count($arResult) == 0) return null; //если результирующий массив пустой - возвращаем null
		else return $arResult; //иначе возвращаем выборку
	} else return $arData; //если возвращать false - могут быть похерены данные, если исходный "массив" - может не произойти никаких изменений. Надо подумать, что лучше...
}
//обертка для правильного вызова arrayFilterResult() в callback'е
function arrayFilterResultMulti(&$arData, $mixKey, $arKeys) {
	$arData = arrayFilterResult($arData, $arKeys);
}


function json_readable_encode($in, $indent = 0, $from_array = false) {//EXPERIMENTAL FUNCTION!!!
    $_myself = __FUNCTION__;
	$_escape = function($str) {
		/* //Rules for characters processing while convert in json
		/ => \/
		\ => \\
		> => \u003E
		< => \u003C
		& => \u0026
		' => \u0027
		" => \u0022
		CRLF => \r\n (exactly as you see it - as 4 symbols sequence)
		*/	
		$searchWhat = array('\\', '/', ">", "<", "&", "'", "\"", "\r", "\n", "\t");
		$changeTo = array('\\\\', '\/', '\\u003E', '\\u003C', '\\u0026', '\\u0027', '\\u0022', '\\r', '\\n', '\\t');
		//$changeTo = array('\\u002F', '\\u005C', '\\u003E', '\\u003C', '\\u0026', '\\u0027', '\\u0022', '\\u000D', '\\u000A', '\\u0009');
		return str_replace($searchWhat, $changeTo, $str); //str_replace is much faster than preg_replace
		//может быть потребуется обратная конвертация при импорте
	};
    $out = '';
    foreach ($in as $key=>$value) {
        $out .= str_repeat("\t", $indent + 1);
        $out .= "\"".$_escape((string)$key)."\": ";

        if (is_object($value) || is_array($value)) {
            $out .= "\n";
            $out .= $_myself($value, $indent + 1);
        } elseif (is_bool($value)) {
            $out .= $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            $out .= 'null';
        } elseif (is_string($value)) {
            $out .= "\"" . $_escape($value) ."\"";
        } else {
            $out .= $value;
        }
        $out .= ",\n";
    }

    if (!empty($out)) {
        $out = substr($out, 0, -2);
    }
    $out = str_repeat("\t", $indent) . "{\n" . $out;
    $out .= "\n" . str_repeat("\t", $indent) . "}";

    return $out;
}

