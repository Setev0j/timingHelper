<?php
require_once("helper.functions.php");

/**
 * 
 */
class timingHelper {
	//память
	protected $iMemoryCurrent; //количество памяти, выделенное на процесс. Фиксируется при инициализации объекта и во время измерений
	protected $iMemoryLimit; //ограничение памяти
	protected $fMemoryMargin = 0.2; //критическое количество зарезервированной памяти, которое не должно заниматься. Может быть переопределено в процессе работы экземпляра класса 
	//время
	protected $fStartTimeGlobal; //временная метка - фиксируется при инициализации объекта
	protected $fTimeInProcess; //сколько времени выполняется скрипт
	protected $fTimeLimit; //ограничение по времени выполнения скрипта (берется значение max_execution_time из настроек php)
	protected $fTimeReserved; //зарезервированное время (на бэкап, к примеру)
	//настройки пошагового выполнения 
	protected $arSteps; //последовательность действий. Задается либо передачей готового массива в конструктор, либо в самом конструкторе
	protected $iCurrentStep = false; //текущий выполняемый шаг. В качестве шага будет указан ключ массива $arSteps. Перейти на следующий шаг можно, вызвав $this->ExecuteNextStep()
	protected $bAutoExecute;
	protected $bTerminateOnStepComplete;
    protected $sAfterAutoProcessCall; //метод, который надо вызвать после автовыполнения. Может быть определен в классе-наследнике
	//настройки сохранения данных при прерывании процесса
	protected $bDataRestored; //восстановлены ли данные из временного хранилища
	protected $bStoreInSession; //хранить ли временные данные в сессионных переменных?
    protected $sStorageName; //название хранилища

	function __construct($sStorageName, $bStoreInSession = false, $arSteps = false, $bAutoExecute = false, $bTerminateOnStepComplete = false) {
		//память
		$this->iMemoryLimit = ini_get('memory_limit');
		$this->iMemoryLimit = ($this->iMemoryLimit == -1) ? false : returnBytes($this->iMemoryLimit); //получаем ограничение на размер выделяемой памяти для скрипта
		if (!$this->checkMemory()) return; //если мы не помещаемся в отведенные лимиты сразу при создании - нечего даже начинать ;)
		//время
		$this->fTimeLimit = (ini_get('max_execution_time') > 0) ? floatval(ini_get('max_execution_time')) : false; //если ограничения по времени выполнения скрипта нет - ставим false
		$this->fStartTimeGlobal = microtime(true); //ставим метку времени в начале работы скрипта (в любом случае)
		if (!is_numeric($this->fTimeReserved)) $this->fTimeReserved = floatval(2); //по умолчанию ставим зарезервированное время в 2 секунды (если в классе-потомке еще не переопределили)
		//NOTE! Если надо, $fTimeReserved можно переопределить в конструкторе класса-наследника и подогнать под конкретные нужды
		if (!$this->checkTime()) return; //если мы не помещаемся в отведенные лимиты сразу при создании - нечего даже начинать ;)
		//подготовка к работе с шагами
		$this->bAutoExecute = !!$bAutoExecute;
		$this->bTerminateOnStepComplete = !!$bTerminateOnStepComplete;
		$this->registerSteps($arSteps); //NB! Если в сохраненных временных данных будет присутствовать массив с шагами, то он будет перезаписан
		//работа с хранилищем временных данных
		$this->bStoreInSession = !!$bStoreInSession;
		$iSessionStarted = isSessionStarted(); //bug fix. was: session_status(); //available only for PHP > 5.4.x
		$this->bDataRestored = false; //перед восстановлением данных устанавливаем false в $bDataRestored
        $this->sStorageName = ''.$sStorageName;
		if ($this->bStoreInSession && substr(php_sapi_name(), 0, 3) != 'cli') { //если данные хранятся в сессии, мы не в консоли (TODO: добавить "и сессии разрешены")
			if (!$iSessionStarted) { //если сессия не открыта - открываем 
				session_start();
			}
			if (array_key_exists($sStorageName, $_SESSION) && is_array($_SESSION[$sStorageName])) { //если обнаружено хранилище временных данных - пытаемся восстановить данные оттуда
				foreach ($_SESSION[$sStorageName] as $sDataName => $mixDataValue) { //проходим по всем элементам сохраненного массива
					if (property_exists($this, $sDataName)) $this->{$sDataName} = $mixDataValue; //восстанавливаем данные только, если в экземпляре класса определено такое свойство
				}
				unset($_SESSION[$sStorageName], $sDataName, $mixDataValue);
			} else { //если хранилище данных не найдено - значит, это первый запуск или сессионная переменная не найдена
				//do nothing...
			}
		} else { //если временные данные храним в файле (надо учитывать, что храним в файле json объект, так что ему надо делать json_decode)
			$this->bStoreInSession = false; //на всякий случай
			//echo "<p>Memory used before restore: ".$this->iMemoryCurrent."</p>";//test
			if (is_file($sStorageName)) {
				$arRestoredData = json_decode(file_get_contents($sStorageName), true);
				if (!is_null($arRestoredData)) { //если не произошло никакой ошибки при вычитке временного хранилища данных
					foreach ($arRestoredData as $sDataName => $mixDataValue) { //проходим по всем элементам сохраненного массива
						if (property_exists($this, $sDataName)) $this->{$sDataName} = $mixDataValue; //восстанавливаем данные только, если в экземпляре класса определено такое свойство
					}
					$this->bDataRestored = true;
					unset($arRestoredData, $sDataName, $mixDataValue);
				}
			} else {
				//если мы здесь - значит, файл не найден или мы просто на первом хите
			}
			//$this->checkMemory();//test
			//echo "<p>Memory used after restore: ".$this->iMemoryCurrent."</p>";//test
		} //конец восстановлению данных
		
		//работа с пошаговым режимом
		if ($this->bAutoExecute && count($this->arSteps) > 0) {
			$this->executeSteps();
		}
		
		
	} //end of __construct()
	
	//сохранение временных данных в случае завершения скрипта по таймауту или из-за нехватки времени 
	protected function saveTemporaryData() {
		//ВНИМАНИЕ!!!
		//В любом случае надо сохранять всякие системные вещи, вроде $this->arSteps && $this->iCurrentStep. 
        if (!isset($this->arSteps) || !is_array($this->arSteps) || count($this->arSteps) == 0 || $this->iCurrentStep === false) return false; //если шаги не найдены - прерываем
        $bResult = false;
        if (isset($this->arSteps[$this->iCurrentStep]['arData2Save']) && is_array($this->arSteps[$this->iCurrentStep]['arData2Save'])) { //если данные для сохранения заданы
            //подготавливаем ключи для сохранения
            foreach($this->arSteps as &$arTmpStep) unset($arTmpStep['fStepTimer']);
            unset($arTmpStep);
            $arData2Save = array_merge($this->arSteps[$this->iCurrentStep]['arData2Save'], array('arSteps', "iCurrentStep"));
            $arOutput = array();
            foreach($arData2Save as $sSaveProperty) {
                if (property_exists($this, $sSaveProperty)) $arOutput[$sSaveProperty] = $this->{$sSaveProperty}; //сохраняем указанные свойства
                //возможно, стоило бы передавать только ссылки на свойства. Не помню, можно ли было ссылаться на protected & private свойства. Надо выяснить...
            }
            if ($this->bStoreInSession) { //сохранение в сессии
                $_SESSION[$this->sStorageName] = $arOutput;
                $bResult = true;
            } else { //сохранение в файле
                $bResult = file_put_contents($this->sStorageName, json_encode($arOutput, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE));
            }
        }
        return $bResult;
	}
	
	//пошаговое выполнение задачи
	protected function executeSteps() {
        if (!isset($this->arSteps) || !is_array($this->arSteps) || count($this->arSteps) == 0) return false; //если шаги не найдены - прерываем
		if ($this->iCurrentStep === false) $this->iCurrentStep = 0; //если в $this->iCurrentStep стоит значение по умолчанию, значит мы еще не запускали авто процесс и не сохраняли временные данные, куда обязательно должен быть включен и $this->iCurrentStep
		for($iii = $this->iCurrentStep; $iii < count($this->arSteps); $iii++, $this->iCurrentStep++) { //проходим по всем шагам, последовательно увеличивая счетчик
			$bStepResult = false; //принимаем, как факт, что метод-шаг будет возвращать только true/false
			if ($this->checkMemory() && $this->checkTime()) {
				//$bStepResult = call_user_method($this->arSteps[$iii]['sMethod'], $this); //как понятно из кода, аргументы передавать в методы не получится
				$bStepResult = $this->{$this->arSteps[$iii]['sMethod']}(); //как понятно из кода, аргументы передавать в методы не получится
			} else { //если не хватает памяти или времени на текущую операцию
				$this->saveTemporaryData(); //сохраняем временные данные
			}
			if ($bStepResult) { //если шаг завершился успешно
				if ($this->bTerminateOnStepComplete) { //если нам надо завершать процесс после выполнения шага
				    $this->iCurrentStep++; //переходим "указателем" к следующему шагу
					$this->saveTemporaryData(); //сохраняем временные данные
					break; //принудительно выходим из цикла
				} else { //если завершать процесс не надо
					//do nothing... until next iteration
				}
			} else break;
		}
        if (!is_null($this->sAfterAutoProcessCall) && method_exists($this, $this->sAfterAutoProcessCall)) {
            $this->{$this->sAfterAutoProcessCall}();
        }
        return true;
	}
	
	//метод для установки шагов
	protected function registerSteps($arSteps) {
		if (!is_array($arSteps)) return false; //если передали не массив - прерываем операцию
		foreach ($arSteps as $arTmpStep) { //проходим по входящему массиву, описывающему шаги
			$arStep = array(); //массив, который получаем на выходе
			foreach ($arTmpStep as $sKey => $mixValue) {
				switch ($sKey) {
					case 'sMethod':
						if (is_string($mixValue) && method_exists($this, $mixValue)) $arStep[$sKey] = $mixValue;
						else continue 2; //если метод не найден - прерываем эту итерацию вообще
					break;
					case 'arData2Save':
					case 'arRestoreSequence':
						if (is_array($mixValue) && count($mixValue) > 0) $arStep[$sKey] = $mixValue;
					break;
					//case 'fStepTimer'://это лишнее
					case 'fAvgIterationTime':
					case 'iCurrentIteration':
						$arStep[$sKey] = $mixValue;
					break;
                //остальные ключи игнорируем
				}
			}
			//доп.проверка на наличие имени метода
			if (count($arStep)>0 && isset($arStep['sMethod'])) {
				$this->arSteps[] = $arStep;
				unset($arStep);
			}
		}
		if (count($this->arSteps) > 0) return true;
		else return false;
	}
	
	//Метод проверки памяти, делает 2 вещи:
	//1. Фиксирует количество памяти, которое использованов данный момент (м.б. использовано для отладки)
	//2. Проверяет, не превысили ли мы лимит выделенной памяти, с учетом зарезервированного количества
	//Зарезервированное количество обычно используется при создании хранилища временных данных
	public function checkMemory() {
		$this->iMemoryCurrent = memory_get_usage(true); //получаем полный объем занятой php памяти, фиксируем в т.ч. в самом начале обращения к экземпляру класса
		if ($this->iMemoryLimit === false) return true; //если нет ограничений по использованию памяти - считаем, что проверку прошли
		if ($this->fMemoryMargin > 1) return false; //если у нас множитель зарезервированной памяти больше 1, то проверку память не пройдет по любому
		return (floatval($this->iMemoryCurrent) < (floatval($this->iMemoryLimit)*(1.0-$this->fMemoryMargin)));
	}
	
	//метод проверки оставшегося времени (если это актуально, т.е., если мы не в консоли) 
	public function checkTime() {
		$this->fTimeInProcess = microtime(true) - $this->fStartTimeGlobal; //сколько времени выполняется скрипт - фиксируем в любом случае
		if ($this->fTimeLimit === false) return true; //если ограничения по времени нет - проверка пройдена сразу
		if (($this->fTimeLimit > $this->fTimeReserved) && $this->fTimeInProcess < ($this->fTimeLimit - $this->fTimeReserved)) { //если мы еще не вышли за рамки дозволенного
			if ($this->iCurrentStep !== false) { //если происходит автоматическое выполнение задачи по шагам 
				if (array_key_exists($this->iCurrentStep, $this->arSteps) && array_key_exists('fAvgIterationTime', $this->arSteps[$this->iCurrentStep])) { //если шаг найден и внутри него ведется учет среднего времени на итерацию - проводим дополнительную проверку
					if ($this->fTimeInProcess < ($this->fTimeLimit - $this->fTimeReserved - floatval($this->arSteps[$this->iCurrentStep]['fAvgIterationTime']))) return true; //если время на еще одну итерацию есть - все ок
					else return false; //если времени на еще одну итерацию не хватает - время сохраняться
				} else return true; //если учета среднего времени на итерацию не ведется, либо еще не произведено ни одной итерации - считаем, что проверку мы прошли
				//NB! Здесь есть слабое место - если время на 1 итерацию превысит допустимые лимиты, то процесс либо вылетит по таймауту, либо зафиксируется среднее время, превышающее допустимое время на выполнение скрипта. Это значит, что при следующем проходе, сразу после восстановления данных (если они будут сохранены, конечно), проверка оставшегося времени выполнения не будет пройдена. А это значит, опять сохранение данных, опять запуск - короче, infinite loop. Так что разработчик должен все-таки планировать итерации и шаги так, чтобы оставаться в рамках.
			} else return true; //если мы не в автоматическом режиме - считаем, что проверку времени мы прошли успешно
		} else return false;
	}

	//метод для работы со временем в контексте отдельно взятого шага
	/**
	 * Что делает метод:
	 * 1. Смотрит, есть ли в настройках шага $fStepTimer. Если есть - значит, итерация уже не первая и время уже начало замеряться
	 * 2. Если найден $fStepTimer, вычисляется $fAvgIterationTime для конкретного шага
	 * 3. После производится проверка времени (получается, что она должна производиться перед началом каждой итерации)
	 * 4. Если проверка не увенчалась успехом - вызывается $this->saveTemporaryData(), который сохраняет все данные, описанные в $this->arSteps[$this->iCurrentStep]['arData2Save']
	 */
	protected function checkStepTime() {
		if (isset($this->arSteps[$this->iCurrentStep]['fStepTimer']) && $this->arSteps[$this->iCurrentStep]['fStepTimer'] > 0.001) { //если как минимум 1 итерация уже была
			if (isset($this->arSteps[$this->iCurrentStep]['fAvgIterationTime']) && floatval($this->arSteps[$this->iCurrentStep]['fAvgIterationTime']) > 0.001) {//если среднее время уже рассчитывалось - вычисляем среднее между рассчитанным значением и новым
				$this->arSteps[$this->iCurrentStep]['fAvgIterationTime'] = ($this->arSteps[$this->iCurrentStep]['fAvgIterationTime'] + (microtime(true) - $this->arSteps[$this->iCurrentStep]['fStepTimer']))/2;
			} else { //если не рассчитано - заносим первое значение
				$this->arSteps[$this->iCurrentStep]['fAvgIterationTime'] = microtime(true) - $this->arSteps[$this->iCurrentStep]['fStepTimer'];
			}
		}
		$this->arSteps[$this->iCurrentStep]['fStepTimer'] = microtime(true); //обновляем таймер текущей временной меткой
		//NOTE: расчеты выше мы делаем в любом случае - может потребоваться для отладки и статистики
		if ($this->fTimeLimit === false) return true; //если ограничения по времени нет - проверка пройдена сразу
		//проводим общую проверку времени с учетом среднего времени исполнения итерации на шаге
		if (!$this->checkTime()) { //времени не хватает
			$this->saveTemporaryData(); //вызываем сохранение временных данных перед завершением работы
			return false; //возвращаем false, чтобы шаг завершился и не оставлял "хвостов" в выделенной памяти
		} else return true;
	}
	
}
