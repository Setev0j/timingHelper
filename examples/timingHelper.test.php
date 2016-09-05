<?php
session_start();
set_time_limit(2); //test
$bAjax = (isset($_GET['ajax']));
if ($bAjax) { //if it's ajax request //пока не надо
	//we need to send it to time controller and receive answer from it
	require_once("../lib/helper.functions.php");
	require_once("../lib/timingHelper.php");
	
	class customTimingHelper extends timingHelper {
		//public $ELEMENTS;
		//public $SECTIONS;
		//public $STRUCTURE;
        protected $someProperty;
        public $step1info;
        protected $step2info;
        protected $step3info; //private save failed

		public function __construct($sStorageName, $bStoreInSession = false, $arSteps = false, $bAutoExecute = false, $bTerminateOnStepComplete = false) {
			$this->fTimeReserved = floatval(1); //OVERFLOW value if you need reserve time for save temporary info greater than 2 seconds (by default)
            $this->sAfterAutoProcessCall = 'generateResponse';
            $this->someProperty = 100;
			parent::__construct($sStorageName, $bStoreInSession, $arSteps, $bAutoExecute, $bTerminateOnStepComplete);
		}

        public function step1() {
            $this->step1info = 'First step';
            $this->someProperty++;
            $arConstants = get_defined_constants(true);
            $this->arSteps[$this->iCurrentStep]['iCurrentIteration'] = (isset($this->arSteps[$this->iCurrentStep]['iCurrentIteration'])) ? $this->arSteps[$this->iCurrentStep]['iCurrentIteration'] : 0;
            $iCurrentIteration = & $this->arSteps[$this->iCurrentStep]['iCurrentIteration'];
            for ($i= &$iCurrentIteration; $i<100000; $i++) {
                if ($this->checkStepTime()) shuffle($arConstants);
                else {
                    echo "Interrupted on iteration: " . $i . "\n<br/>";
                    return false;
                }
            }


            return true; //метод должен обязательно возвращать true/false
        }

        public function step2() {
            $this->step2info = 'Second step';
            $this->someProperty++;
            usleep(1000);
            return true;
        }

        public function step3() {
            $this->step3info = 'Third step';
            $this->someProperty++;
            usleep(1000);
            return true;
        }

        protected function generateResponse() {
            $arOutput = array($this->someProperty, $this->step1info, $this->step2info, $this->step3info);
            $sOutput = print_r($arOutput, true);
            echo "<p>Current step: " . $this->iCurrentStep .
            "</p><pre>Step work result:\n $sOutput \n
            Session data: \n
            ".json_encode($_SESSION)."
            </pre>";
        }

	}

    $arSteps = array(
        array('sMethod' => 'step1', 'arData2Save' => array('step1info', 'someProperty')),
        array('sMethod' => 'step2', 'arData2Save' => array('step1info', 'step2info', 'someProperty')),
        array('sMethod' => 'step3', 'arData2Save' => array('step2info', 'step3info', 'someProperty'))
    );
	$testController = new customTimingHelper("testController", true, $arSteps, true, false); //$sStorageName, $bStoreInSession = false, $arSteps = false
	//echo "<pre>";print_r($testController);echo "</pre>";
	
} else { unset($_SESSION['test.controller'], $_SESSION['testController']);
?>
	
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<title>Time controller test</title>
</head>
<body>
<h1>Time controller test</h1>
<a class="process">Click to start the process</a>
<div class="results"></div>

<script src="//code.jquery.com/jquery-1.11.3.min.js"></script>
<script>
    $(function() {
        $.ajaxSetup({
            url: "timingHelper.test.php",
            data: {"ajax": true},
            type: "GET",
            cache: false,
            crossDomain: false
        });
        $(".process").on( "click", function() {
            $.ajax().
                done(function( data, textStatus, jqXHR ) {
                    $("div.results").append("<p>"+data+"</p>");
                }).
                fail(function( jqXHR, textStatus, errorThrown ) {
                    $("div.results").append("<p>Error: "+textStatus+"</p>");
                });
        });
    });
</script> 
</body>
</html>
<?}?>