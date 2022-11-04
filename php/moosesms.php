<?php
/**
 * Created by Alexander Purikov for mooServer project
 * 2014 - 2015
 */
if (!defined('IN_MOOSE'))
	exit;

class CMooseSMS
{
	var $id;		// id, вынутый из смс
	var $text;

	var $volt;
	var $temp;
	var $gsmTries;
	var $gpsOn;

	var $time;		// время получения смс гейтом
	var $points;		// массив троек ширина, долгота, время
	var $activity;		// массив пар

	var $diag;
	
	var $TestValue;
	var $TestValue2;
	
	var $IsOk;
	var $ErrorMessage;
	var $WarningMessage;
	
	var $RotateHour;
	
	const PROPRIETARYBASE64_INDEX = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz&#";
	const Artificial = "ArtificialSmsPrefix ";
	const GateGrace = 10800;	// прощаем, если часы гейта убегают от сервера на 3 часа
	const PointGrace = 43200;	// прощаем, если точки убегают на 12 часов в будущее от гейта или сервера

	
	function __construct($text, $time = null)
	{
		$this->id = time();
		
		$this->IsOk = TRUE;
		$this->ErrorMessage = '';
		
		$this->text = $text;
		
		$this->time = ($time != null) ? $time : time();

		$this->volt = 7.1;
		$this->temp = 17.3;
		$this->gsmTries = 3;
		$this->gpsOn = $this->gsmTries * 2.7;
		
		$this->points = [];
		$this->activity = null;
		$this->diag = null;
		
		$this->RotateHour = 17;
		
		$this->TestValue = "Pre";
		$this->TestValue2 = '';
		//$this->TestValue = CMooseSMS::a64toi ("9");

		//$this->ProcessSMSText ();
	}


	public static function CreateFromText ($text, $time, $diagnostic = 0)
	{
		if (strpos($text, self::Artificial) === 0)
			return self::artificialSms($time);


		if (CMooseSMSv1::matches($text))
			$res = new CMooseSMSv1($text, $time);
		else
			//die ("doesn't match");
			$res = new CMooseSMSv3($text, $time);
		
		$res->ProcessSMSText($diagnostic);
		
		return $res;
	}

	// if you provide text for sms, it should be unique for the moose
	public static function artificialSms($time, $text = null)
	{
		if ($text == null)
			$text = sprintf("%s %d %s", self::Artificial, $time, microtime());
		else if (strpos($text, self::Artificial) !== 0)
			$text = self::Artificial . ' ' . str_pad($text, 50 - 1 - strlen(self::Artificial), ' ', STR_PAD_LEFT);

		$res = new CMooseSMS($text, $time);

		$res->temp = 0;
		$res->volt = 5;
		$res->gpsOn = 0;
		$res->gsmTries = 0;

		return $res;
	}

	/*
	public static function fromBulk($time, $text, $id, $points, $activity)
	{
		$virtualtime = time();
		$res = new CMooseSMS($text, strtotime ($time));
		$res->id = $id;
		$res->points = $points;

		$len = count($res->points);
		for ($i = 0; $i < $len; $i++)
			$res->points[$i][2] = (isset($res->points[$i][2]) ? strtotime($res->points[$i][2]) : $virtualtime++);

		$res->activity = $activity;
		$res->IsOk = TRUE;

		return $res;
	}*/

	/**
	0 -- none,
	1 -- slight
	2 -- hardcore
	*/
	protected function ProcessSMSText ($diagLevel)
	{
	}
	
	public static function a64toi ($aStr)
	{
		$Result=0;
		$StringLength = strlen ( $aStr );
		//echo "--$StringLength--";
		for ( $i=0 ; $i<$StringLength ; $i++ )
		{
			$Result*=64;
			$NextCharDecoded=strrpos( self::PROPRIETARYBASE64_INDEX, $aStr{$i} );
			if ( $NextCharDecoded === FALSE )
				return FALSE;
			$Result+= $NextCharDecoded;
		}
		return $Result;
		
	}
	
	public static function a64bitstoi ($aStr, $aStartBit, $aNBits) //aStartBit -- самый младший (т.е. биты считаются справа).
	{
		$Result=0;
		$StringLength = strlen ( $aStr );

		if ( (integer)floor(($aStartBit+$aNBits-1)/6)-1 > $StringLength )
			return FALSE;

		for ( $i=$StringLength-(integer)floor(($aStartBit+$aNBits-1)/6)-1 ; $i<$StringLength-(integer)floor($aStartBit/6) ; $i++ )
		{
			$Result*=64;
			$NextCharDecoded=strrpos( self::PROPRIETARYBASE64_INDEX, $aStr{$i} );
			if ( $NextCharDecoded === FALSE )
				return FALSE;
			$Result+= $NextCharDecoded;
		}
	
		$Result >>= $aStartBit % 6;
		$Result &= (1 << $aNBits) - 1;
		
		return $Result;
	}

	/**
	 * The message does not carry a year, which it's generated in.
	 * If the day in sms is after the receiving one, most probably, it was in the previous year.
	 * That's expected behaviour for the end of the year, but let's add diagnostics for other ones;
	 */
	protected function GetEpochYear($dayOfYear)
	{
        $epochYear = gmdate("Y", $this->time);
		$receivedOn = gmdate("z", $this->time); // 0-based day of year

		if ($dayOfYear - 1 > $receivedOn)	// $dayOfНear is 1-based, $receivedOn -- 0-based
		{
			$epochYear--;
			$receivedOn++;
			if ($dayOfYear < 365 - 14 || $receivedOn > 31)
				$this->AddDiag("Точки из '$dayOfYear' дня получены в '$receivedOn' день года. Возможно, ошибочно определен '$epochYear' год.");
		}

		return $epochYear;
	}
	
	public function GetTest ()
	{
		$Result = "";
		//return $this->$TestValue1;
		/*

		$Result .= CMooseSMS::a64toi ("A0");
		$Result .= "=640, ";
		$Result .= CMooseSMS::a64toi ("A00#");
		$Result .= "=2621503, ";
		$Result .= CMooseSMS::a64bitstoi("WUtyNu",30,6);
		$Result .= "=32, ";
		$Result .= CMooseSMS::a64bitstoi("WUtyNu",12,12);
		$Result .= "=3580, ";
		$Result .= CMooseSMS::a64bitstoi("rRwWMIycqV0m",32,8);
		$Result .= "=303, ";
		$Result .= CMooseSMS::a64bitstoi("rRwWMIycqV2m",0,11);
		$Result .= "=176, ";*/


		$this->TestValue = "Tech: ID=";
		$this->TestValue .= $this->id;
		$this->TestValue .= " gsmTryes=";
		$this->TestValue .= $this->gsmTries;
		$this->TestValue .= " gpsTime=";
		$this->TestValue .= $this->gpsOn;
		$this->TestValue .= " Voltage=";
		$this->TestValue .= $this->volt;
		$this->TestValue .= " Temperature=";
		$this->TestValue .= $this->temp;
		$this->TestValue .= " Lat=";
		$this->TestValue .= $this->points[0][0];
		$this->TestValue .= " Long=";
		$this->TestValue .= $this->points[0][1];
		$this->TestValue .= " Test=";
		$this->TestValue .= $this->TestValue2;
		$this->TestValue .= " Time=";
		$this->TestValue .= $this->points[0][2];
		return $this->TestValue;
	}

	function SetError ( $ErrorText )
	{
		$this->IsOk = FALSE;
		$this->ErrorMessage = $ErrorText;
		$this->TestValue2 = $ErrorText;
	}
	
	public function IsValid ()
	{
		return $this->IsOk;
	}

	public function HasData ()
	{
		return $this->activity != null && count($this->activity) > 0 || $this->points != null && count($this->points) > 0;
	}
	
	public function GetErrorMessage ()
	{
		return $this->ErrorMessage;
	}

    public function GetWarningMessage ()
    {
        return $this->WarningMessage;
    }

    private static function AlterTime($year, $month, $day, $monthStep = 0)
	{
		if ($monthStep != 0)
		{
			$mon2 = ($month + 11 + $monthStep) % 12 + 1;

			if (abs($month - $mon2) > 1)
				$year += $monthStep / abs($monthStep);

			$month = $mon2;
		}

		return gmmktime(0, 0, 0, $month, $day, $year);
	}

    public static function GetActivityBaseDate($receivedOn, $refDate, $activityDay)
    {
    	$refDay = gmdate('j', $refDate);
    	$refMon = gmdate('n', $refDate);
    	$refYear = gmdate('Y', $refDate);

    	$notLater = min($receivedOn, time());
		$r = self::AlterTime($refYear, $refMon, $activityDay, 0);

		/*	ref 	Act  	now
	 	 *	10.08	8       10.08  ok
		 *  10.08   12      10.08  step = -1    $r > notLater
		 * 	10.08	31      10.08  step = -1    $r > notLater
		 *  10.08   28      29.08  step = 0 ?
		 *  28.08   2       31.08  step = 0
		 *  28.08   2        3.09  step = +1		*/

		// а что там с краями месяца / года?
    	if (abs($refDay - $activityDay) > 14 || $r > $notLater) {

			$step = $activityDay > $refDay || $r > $notLater ? -1 : 1;
			$r = self::AlterTime($refYear, $refMon, $activityDay, $step);
			if ($step > 0 && $r > $notLater)	// activity действительно сильно отстает и от now и от точек
				$r = self::AlterTime($refYear, $refMon, $activityDay, 0);
		}


		//	echo date('c', $refDate) ." ad: $activityDay refdate day: ". gmdate('j', $refDate) ." m: " . gmdate('n', $refDate) . ' ' .  date('c', $r). '<br/>';
		return $r;
    }

	protected function CheckDate($serverNow, $test, $tag = "")
	{
		if ($test > $serverNow && $this->WarningMessage == null) {
			$gate = gmdate("c",  $this->time);
			$ref =  gmdate("c",  $serverNow);
			$test = gmdate("c",  $test);

			$this->WarningMessage = "$tag: parsed time in future: \nserver: $ref \ntest: $test \ngate: $gate, sms: $this->text";
        }

        return $test;
	}

	protected function ProcessTimeDiagnostic()
	{
		$now = time();

		if ($this->time > $now + self::GateGrace)
			$this->AddDiag('Gate timestamp is later than server one!');

		$cn = count($this->points);
		if ($cn > 0)
		{
			if ($this->points[0][2] > $this->time + self::PointGrace || $this->points[$cn - 1][2] > $this->time + self::PointGrace)
				$this->AddDiag('point timestamp is later than gateway one!');
			else if ($this->points[0][2] > $now + self::PointGrace || $this->points[$cn - 1][2] > $now + self::PointGrace)
				$this->AddDiag('point timestamp is later than server one!');
		}
	}

	protected function AddDiag ($msg)
	{
		if ($this->diag == null)
			$this->diag = '';

		if ($this->diag != '')
			$this->diag .= ', ';

		$this->diag .= $msg;
	}
}

class CMooseSMSv3 extends CMooseSMS
{
	var $CompressionFactor;
	var $CompressionType;
	
	const TECH_HEADER_LENGTH = 6;
	const ACTIVITY_LENGTH = 25;
	const POINTS_HEADER_LENGTH = 12;

	var $TechHeaderText;
	var $ActivityText;
	var $PointsHeaderText;
	var $PointsArrayText;

	// region reload diagnostics
	static $RunPoints = ["Default", "NotValid", "StateMachine", "putcUART2", "putStrUART2", "PowerSM", "U2RXInt", "MainSM",
		"CHalt", "ReadEEPROM", "WriteEEPROM", "RFSM", "CHalt2", "SendSMS", "SendSMS2", "TX7021", "RX7021"];
	static $ReloadReasons = ["Power-on reset", "Brown-out Reset", "Idle mode", "Sleep mode", "Watchdog timeout", "WDT is enabled", "Software Reset",
		"Master Clear (pin) Reset", "Program memory bias voltage remains powered during Sleep", "A Configuration Word Mismatch Reset", "Deep Sleep mode",
		"Unimplemented 11", "Retention mode is enabled while device is in Sleep modes", "Unimplemented",
		"An illegal opcode detection, an illegal address mode or Uninitialized W register is used as an Address Pointer", "Trap Conflict Reset"];

	static $BeaconStates = [255 => "Just turned on", // it was -1
		0 => "MAIN",
		2 => "EVENT_ACQ",
		3 => "EVENT_SND",
		4 => "INITTIMEOUT",
		5 => "TURNONPHONE",
		6 => "TURNOFFPHONE",
		7 => "GETPOSITION",
		8 => "INITGPS",
		9 => "WAITAQ",
		10 => "EXITGPS",
		11 => "SENDPOSITION",
		12 => "WAITSENDOK",
		13 => "WAITGOODSIGNAL",
		14 => "SENDPOSSMS",
		15 => "WAITSMSANSWERPHONE",
		16 => "TURNOFFGPS",
		17 => "SENDOK",
		18 => "GETSCANUMBER",
		19 => "WAITSCANUMBER",
		33 => "TRACKGPS",
		34 => "GPSDONE",
		35 => "RETURNTOMAIN",
		36 => "TRYTOSENDPOSITION",
		37 => "CHECKEXITGPS",
		38 => "WAITEXITGPS",
		39 => "WAITPHONEON",
		40 => "SENDATTURNON",
		41 => "CHECKREG",
		42 => "ASKREG",
		43 => "WAITREG",
		46 => "FIXUNSUCCESSFUL",
		47 => "DELAYAQ",
		49 => "FIXPOS",
		50 => "SAVEPOS",
		51 => "INITIALSEQENCE",
		52 => "RETURNTOMAIN2",
		53 => "KILLSPAM",
		54 => "WAITKILLSPAM",
		55 => "WAIT_FOR_ACTIVE",
		56 => "WAIT_FOR_NOT_ACTIVE",
		57 => "SENDPHASE2",
		58 => "TURNOFF",
		59 => "TURNOFF_PHASE2",
		60 => "SKIP",
		61 => "TURNOFF_PHASE3"];
	// endregion

	protected function ProcessSMSText ($diagLevel)
	{
		$this->TestValue = "Process";
		if ( strlen ( $this->text ) < self::TECH_HEADER_LENGTH + self::ACTIVITY_LENGTH + self::POINTS_HEADER_LENGTH )
		{
			$this->SetError ( 'Message is too short!' );
			return;
		}
			
		for ( $i=0 ; $i<strlen ( $this->text ) ; $i++ )
			if ( strrpos( self::PROPRIETARYBASE64_INDEX, $this->text{$i} ) === FALSE )
			{
				$this->SetError ( 'Invalid character in message!') ;
				return;
			}
				
		$this->TechHeaderText = substr ( $this->text, 0, self::TECH_HEADER_LENGTH );
		$this->ActivityText = substr ( $this->text, self::TECH_HEADER_LENGTH, self::ACTIVITY_LENGTH );
		$this->PointsHeaderText = substr ( $this->text, self::TECH_HEADER_LENGTH + self::ACTIVITY_LENGTH, self::POINTS_HEADER_LENGTH );
		$this->PointsArrayText = substr ( $this->text, self::TECH_HEADER_LENGTH + self::ACTIVITY_LENGTH + self::POINTS_HEADER_LENGTH );

		$this->ProcessTechHeader ($diagLevel);
		$this->ProcessPointsHeader ($diagLevel);
		if ( $this->IsOk )
			$this->ProcessPointsArray ($diagLevel);

		$this->ProcessActivity ($diagLevel);

		$this->ProcessTimeDiagnostic();
	}
	
	protected function ProcessTechHeader ($diagLevel)
	{
		$this->id = CMooseSMS::a64bitstoi ( $this->TechHeaderText, 30, 6 );
		$this->gsmTries = CMooseSMS::a64bitstoi ( $this->TechHeaderText, 24, 6 );
		$this->gpsOn = CMooseSMS::a64bitstoi ( $this->TechHeaderText, 12, 12 );
		$this->volt = CMooseSMS::a64bitstoi ( $this->TechHeaderText, 6, 6 );
		$this->temp = CMooseSMS::a64bitstoi ( $this->TechHeaderText, 0, 6 );
		
		if ( $this->id === FALSE || $this->gsmTries === FALSE || $this->gpsOn === FALSE ||
			 $this->volt === FALSE || $this->temp === FALSE )
		{
			$this->SetError ( "Message processing failed (internal error in tech header routine)! header: '$this->TechHeaderText'" );
			return;
		}	 
		
		$this->volt = $this->volt*0.01 + 3.20;

		if ( $this->temp > 30 )
			$this->temp -= 65;

		if ($diagLevel > 0)
			$this->AddDiag("Header: $this->TechHeaderText, id: $this->id, gsm: $this->gsmTries, gps: $this->gpsOn, volt: $this->volt, t: $this->temp");
	}
	
	protected function ProcessPointsHeader ($diagLevel)
	{
		$LatDegree = CMooseSMS::a64bitstoi ( $this->PointsHeaderText, 59, 7 );
		$LatPartsOfDegree = CMooseSMS::a64bitstoi ( $this->PointsHeaderText, 43, 16 );
		$LongDegree = CMooseSMS::a64bitstoi ( $this->PointsHeaderText, 35, 8 );
		$LongPartsOfDegree = CMooseSMS::a64bitstoi ( $this->PointsHeaderText, 20, 15 );
		
		$DayOfYear = CMooseSMS::a64bitstoi ( $this->PointsHeaderText, 11, 9 );
		$TimeOfDayIn10MinIntervals = CMooseSMS::a64bitstoi ( $this->PointsHeaderText, 3, 8 );
		$this->CompressionFactor = CMooseSMS::a64bitstoi ( $this->PointsHeaderText, 66, 4 );
		$this->CompressionType = CMooseSMS::a64bitstoi ( $this->PointsHeaderText, 70, 2 );

		if ($diagLevel > 0)
			$this->AddDiag("<br/>pts hdr: '$this->PointsHeaderText', doy: $DayOfYear, minutesOfDay: " . $TimeOfDayIn10MinIntervals * 60 . " ct: $this->CompressionType, cf: $this->CompressionFactor");
		
		if ( $LatDegree === NULL || $LatPartsOfDegree === NULL || $LongDegree === NULL ||
			 $LongPartsOfDegree === NULL || $DayOfYear === NULL || $TimeOfDayIn10MinIntervals === NULL ||
			 $this->CompressionType === NULL || $this->CompressionFactor === NULL )
		{
			$this->SetError ( 'Message processing failed (internal error in points header routine)!' );
			return;
		}
		
		if ( $LatDegree == 0 && $LatPartsOfDegree==0 && $LongDegree==0 && $LongPartsOfDegree==0 &&
		      $DayOfYear==0 && $TimeOfDayIn10MinIntervals==0 ) // This is a 'null point'
			  {
			  $this->SetError ( "There are no any meaning points in the message." );
			  return;
			  }
		
		$NewPoint[0] = $LatDegree + $LatPartsOfDegree/65536;
		$NewPoint[1] = $LongDegree + $LongPartsOfDegree/32768;
		$epochYear = $this->GetEpochYear($DayOfYear);
		$sec = ($DayOfYear-1) * 24 * 60 * 60 +
			$TimeOfDayIn10MinIntervals * 60 * 10;
		$NewPoint[2] = gmmktime (0, 0, 0, 1, 1, $epochYear) +
						$sec;
		//$this->TestValue2 = CMooseSMS::a64bitstoi ( $this->PointsHeaderText , 35, 8 );

		if ($diagLevel > 0) {
			$dtStr = gmdate('Y-m-d', $NewPoint[2]) .'T'. gmdate('H:i:s', $NewPoint[2]). 'Z';
			$this->AddDiag(" yr: $epochYear sec: $sec <br/>res: $NewPoint[2] ($dtStr) <br/>");
		}
		
		$this->points[] = $NewPoint;
	}
	
	protected function ProcessPointsArray ($diagLevel)
	{
		if ( $this->CompressionType != 3 )
		{
			$this->SetError ( "Unsupported compression: $this->CompressionFactor/$this->CompressionType" );
			return;
		}

		$dSkip = 0;
		$PointLength = 5;
		$XFieldLength = 11;
		$YFieldLength = 11;
		$dTimeLength = 8;
		$dTimeTick = 60 * 10;
		$LongCorrectionFactor = cos ($this->points[0][0]*pi()/180);
		if ( $LongCorrectionFactor == 0 )
		{
			$this->SetError ( 'Internal error: LongCorrFactor==0!' );
			return;
		}
		
		$Kfactor = 1<<$this->CompressionFactor;
			
		$XFieldCapacity = 1<<$XFieldLength;
		$YFieldCapacity = 1<<$YFieldLength;

		if ($diagLevel > 0)
			$this->AddDiag("pts: $this->PointsArrayText <br/> kFact: $Kfactor, x: $XFieldLength, $XFieldCapacity, y: $YFieldLength, $YFieldCapacity");


		$XLimit = $XFieldCapacity/2 - $XFieldCapacity/80;
		$YLimit = $YFieldCapacity/2 - $YFieldCapacity/80;

		//$this->TestValue2 .= " Kfactor=$Kfactor, XFieldCapacity=$XFieldCapacity, YFieldCapacity=$YFieldCapacity";

		$refTime = time();
		
		for ( $i = 0 ; $i+$PointLength <= strlen ( $this->PointsArrayText ) ; $i+=$PointLength )
		{
			$PointText = substr ( $this->PointsArrayText, $i, $PointLength );
			$X = CMooseSMS::a64bitstoi ( $PointText, $dTimeLength+$YFieldLength, $XFieldLength );
			$Y = CMooseSMS::a64bitstoi ( $PointText, $dTimeLength, $YFieldLength );
			$dTime = CMooseSMS::a64bitstoi ( $PointText, 0, $dTimeLength );
			
			if ($X === NULL || $Y === NULL || $dTime === NULL)
				{
					$this->SetError ( "Message processing failed (internal error in points routine)!" );
					return;
				}
			
			if ($X >= $XFieldCapacity/2) $X-=$XFieldCapacity;
			if ($Y >= $YFieldCapacity/2) $Y-=$YFieldCapacity;
			
			//$this->TestValue2 .= "i=$i X=$X, Y=$Y   ";
			
			$dLat=1/$Kfactor*(tan ( $X*pi()/$XFieldCapacity) + tan ( ($X+1)*pi()/$XFieldCapacity))/2;
			$dLong=1/($LongCorrectionFactor*$Kfactor) * 
					(tan ( $Y*pi()/$YFieldCapacity) + tan ( ($Y+1)*pi()/$YFieldCapacity))/2;
					
			if ( $X > -$XLimit && $X < $XLimit &&
				 $Y > -$YLimit && $Y < $YLimit )
			{
				// If the value is close to the range edge, it's most probably invalid.
				$Point[0] = $this->points[0][0] + $dLat;
				$Point[1] = $this->points[0][1] + $dLong;
				$Point[2] = $this->points[0][2] + $dTime*$dTimeTick;
				$this->CheckDate($refTime, $Point[2], "point");
				$Point[3] = 1;

				$this->points[] = $Point;
			}
			else
			{
				$dSkip++;
			}
		}

		if ( $dSkip > 0 )
			$this->ProcessSkippedDiagnostic ( $dSkip );
	}
	
	protected function ProcessActivity ($diagLevel)
	{
		$this->TestValue2 .= $this->ActivityText;
		$this->TestValue2 .= '.';
		
		$ActivityDay = CMooseSMS::a64bitstoi ( $this->ActivityText, 24*6 , 6 );
		
		if ( $ActivityDay === NULL )
		{
			$this->SetError ( "Message processing failed (internal error in activity routine)! Activity text: '$this->ActivityText'" );
			return;
		}

		if ($diagLevel > 0)
			$this->AddDiag("<br/> act: $this->ActivityText, ActDay: $ActivityDay");
		
		if ( $ActivityDay >= 32 ) //These values are reserved for special usage (tests etc.)
		{
			if ( $ActivityDay == 32 )
				$this->ProcessReloadDiagnostic ();
			else
				$this->AddDiag("incorrect ActivityDay: $ActivityDay");

			return;
		}

		$hasPoints = count($this->points) > 0;
		$refTime = $hasPoints ? $this->points[0][2] : time();
		if ($hasPoints && ($refTime === null || $refTime == 0))
		{
            $this->SetError ( "Message has no valid date" );
            return;
        }

        $now = time();
        $ActivityDate1 = self::GetActivityBaseDate($this->time, $refTime, $ActivityDay);
        if (gmdate('j', $ActivityDate1) != $ActivityDay)
        	$this->AddDiag("Sms ActivityDay $ActivityDay doesn't match decoded date " . gmdate('Y-m-d',$ActivityDate1));

        $ActivityDate2 =  $ActivityDate1 + 24 * 60 * 60;


		for ( $i=0 ; $i<24 ; $i++ )
			for ( $j=0 ; $j<6 ; $j++ )
			{
				$CurrentActivity[1] = CMooseSMS::a64bitstoi ( $this->ActivityText, (23-$i)*6 + $j, 1 );
				$CurrentActivity[0] = (($i < $this->RotateHour ) ? $ActivityDate2 : $ActivityDate1 ) + $i*60*60 + $j*10*60;
				if ( $CurrentActivity[1] === NULL )
				{
					$this->SetError ( "Message processing failed (internal error in activity routine)!" );
					return;
				}
				$this->activity[] = $CurrentActivity;
				$this->TestValue2 .= $CurrentActivity[1];
				$this->CheckDate($now, $CurrentActivity[0], 'activity');
			}

		if ($diagLevel > 0) {
			$dtStr = gmdate('Y-m-d',$ActivityDate1) .'T'. gmdate('H:i:s', $ActivityDate1). 'Z';
			$this->AddDiag("refTime: $refTime,  hasPoints: " . ($hasPoints ? 1 : 0) . ", actDate: $ActivityDate1 ($dtStr)");
		}
	}
	
	protected function ProcessReloadDiagnostic ()
	{
		$text = substr($this->ActivityText, 0, 12);

		$day = CMooseSMS::a64bitstoi($text, 60, 6);
		$hour = CMooseSMS::a64bitstoi($text, 54, 6);
		$minute = CMooseSMS::a64bitstoi($text, 48, 6);
		$second = CMooseSMS::a64bitstoi($text, 42, 6);

		$reloadsCounter = CMooseSMS::a64bitstoi($text, 10, 8);
		$this->AddDiag("Reload on day $day at $hour:$minute:$second GMT, reloads: $reloadsCounter");

		$rCon = CMooseSMS::a64bitstoi($text, 26, 16);
		$runPoint = CMooseSMS::a64bitstoi($text, 2, 8);
		$sysState = CMooseSMS::a64bitstoi($text, 18, 8);

		$occured = [];
		foreach (self::$ReloadReasons as $k => $v)
			if ((($rCon >> $k) & 1) != 0)
				$occured[] = $v;
		$this->AddDiag("RCON: $rCon " . implode(", ", $occured));

		$this->AddDiag("SysState: $sysState: ". @self::$BeaconStates[$sysState]. ", Runpoint: $runPoint: " . @self::$RunPoints[$runPoint]);
	}

	protected function ProcessSkippedDiagnostic ($numSkipped)
	{
		$this->AddDiag("$numSkipped points skipped");
	}
}

class CMooseSMSv1 extends CMooseSMS
{
    var $TechHeaderText;
    var $ActivityText;
    var $PointsHeaderText;
    var $PointsText;

    var $gsmOn;
    var $gpsTries;
    var $uptime;
    var $nmearns;

    var $format;
    var $formatVer;

    var $refDate = null;


    const regEx = "/\s*([^\s]+)\s+([^\s]+)((\s+[^\s]+)*)/";

    const TYPE_OTHER = -1;
    const TYPE_THURAYA = 1;
    const TYPE_ERIC = 2;
    const TYPE_COMMON = 3;

    protected function ProcessSMSText($diagLevel)
    {
        if (!mb_check_encoding($this->text, 'UTF-8'))
        {
            $this->SetError("string not in ut8 encoding");
            return;
        }

        if (preg_match(self::regEx, $this->text, $matches) != 1)
        {
            $this->SetError("string doesn't look like sms");
            return;
        };

        $this->TechHeaderText = $matches[1];
        $this->ActivityText = $matches[2];
        $tail = count($matches) > 3 ? $matches[3] : "";
        $verId = mb_substr($tail, 0, 2);


        $this->formatVer = self::a64toi($verId);

        // echo "e $tail e" . count($matches) . " fff $verId gg $this->formatVer g <br/> .";
        $points = [];
        $m2 = 0;

        if (preg_match('/\d/u', $verId) || preg_match('/x{16}/', $tail))
        {
            $this->PointsText = $tail;
            $points[] = preg_split("/\s+/u", $matches[3], null, PREG_SPLIT_NO_EMPTY);

            // echo print_r($points, 1) . "<br/>";
            $this->PointsHeaderText = '0';
            $this->formatVer = 0;
        }
        else if ($this->formatVer == 10)
        {
            $this->PointsHeaderText = mb_substr($tail, 0, 10);
            $this->PointsText = mb_substr($tail, 10);

            die ("not tested!!!! A");
            if (preg_match("/(.{5})+/", $this->PointsText, $m2)) {

               //echo "ver A";

                for ($i = 1; $i < count($m2); $i++)
                    $points[] = $m2[$i];
            }
            else
                die("figna 1");
        }
        else //if ($verId[0] >= 'G' && $verId <= 'V')  $this->formatVer == 10
            if ($this->formatVer >= 16 && $this->formatVer < 48)
        {
            $this->PointsHeaderText = mb_substr($tail, 0, 24);
            $this->PointsText = mb_substr($tail, 22);

            die ("not tested!!!! GV");
            // точки -- куски по 4
        }
        else if ($this->formatVer >= 48 && $this->formatVer < 64)
        {
            $this->PointsHeaderText = mb_substr($tail, 0, 24);
            $this->PointsText = mb_substr($tail, 22);
            die ("not tested!!!! 48-64");
            // точки -- куски по 5
        }

        $this->format = self::TYPE_OTHER;

//		echo print_r($matches, 1) ."<br/><br/>" . print_r($m2, 1);

        $this->ProcessTechHeader();
        $this->ProcessPointsHeader();
        if ($this->IsOk)
            $this->ProcessPointsArray();
        if ($this->IsOk)
            $this->ProcessActivity($this->refDate);

		$this->ProcessTimeDiagnostic();
    }

    static function matches($text)
    {
        return preg_match(self::regEx, $text);
    }

    protected function ProcessTechHeader()
    {
        // GID,            \tID,\tGPStrs,\tGSMtrs,\tUpTime,\tGPStm,\tGSMtm,\tV\tT\tNMEArns\n"; -- thuraya
        // GID,		       \tID,\tGPStrs,\tGSMtrs,\tUpTime,\tGPStm,\tGSMtm,\tV\n";				-- eric
        // GID,            \tID,\tGPStrs,\tGSMtrs,\tUpTime,\tGPStm,\tGSMtm,\tV\tT\n";			-- ancient
        // GID,            \tID,         \tGSMtrs,         \tGPStm,        \tV\tT\n";			-- v3

        $ericRe    = "/(.)(.)(.)(...)(...)(...)(.)/";
        $commonRe  = "/(.)(.)(.)(...)(...)(...)(.)(.)/";		// uptime: 3
        $thurayaRe = "/(.)(.)(.)(..)(.)(...)(...)(.)(.)/";		// uptime: 2, nmearn: 1

//		echo "<br/>'{$this->TechHeaderText}'<br/>";


        /*if (preg_match($thurayaRe, $this->TechHeaderText, $matches))
        {
            $this->format = self::TYPE_THURAYA;

            $this->id = self::a64toi($matches[1]);
            $this->gpsTries = self::a64toi($matches[2]);
            $this->gsmTries = self::a64toi($matches[3]);
            $this->uptime = self::a64toi($matches[4]);
            $this->gpsOn = self::a64toi($matches[6]);
            $this->gsmOn = self::a64toi($matches[7]);
            $this->volt = self::a64toi($matches[8]) * 0.1 + 6.0;
            $this->temp = self::a64toi($matches[9]);
            if ($this->temp > 30)
                $this->temp -= 65;
            $this->nmearns = self::a64toi($matches[5]);
        }
        else */if (preg_match($commonRe, $this->TechHeaderText, $matches)) // old
        {
            $this->format = self::TYPE_COMMON;

            $this->id = self::a64toi($matches[1]);
            $this->gpsTries = self::a64toi($matches[2]);
            $this->gsmTries = self::a64toi($matches[3]);
            $this->uptime = self::a64toi($matches[4]);
            $this->gpsOn = self::a64toi($matches[5]);
            $this->gsmOn = self::a64toi($matches[6]);
            $this->volt = self::a64toi($matches[7]) * 0.01 + 3.20;
            $this->temp = self::a64toi($matches[8]);
            if ($this->temp > 30)
                $this->temp -= 65;
        }
        else if (preg_match($ericRe, $this->TechHeaderText, $matches))
        {
            $this->format = self::TYPE_ERIC;

            $this->id = self::a64toi($matches[1]);
            $this->gpsTries = self::a64toi($matches[2]);
            $this->gsmTries = self::a64toi($matches[3]);
            $this->uptime = self::a64toi($matches[4]);
            $this->gpsOn = self::a64toi($matches[5]);
            $this->gsmOn = self::a64toi($matches[6]);
            $this->volt = self::a64toi($matches[7]) * 0.1 + 6;
        }
        else
            $this->SetError("error parsing header");
    }

    protected function ProcessPointsHeader()
    {
        if ($this->PointsHeaderText != "0")
            $this->SetError("untested version");
    }

    protected function ProcessPointsArray()
    {
        $this->points = [];

        $refDate = time();

        $lastDay = 0;
        $lastMonth = 0;
        if ($this->formatVer == 0)
            foreach (preg_split("/\s+/", $this->PointsText, null, PREG_SPLIT_NO_EMPTY) as $pText)
                if (($pt = $this->ParseOldPoint($pText, $lastMonth, $lastDay, $refDate)) != null)
                    $this->points[] = $pt;
    }

    private function ParseOldPoint($text, &$lastMonth, &$lastDay, $refDate)
    {
        if (preg_match("/(\d{2})(\d{6})(\d{3})(\d{6})(.)(.)(.)(.)/", $text, $m) == 1)
            return [ $m[1] + ("0.$m[2]" * 10000000/6) / 1000000.0,
                     $m[3] + ("0.$m[4]" * 10000000/6) / 1000000.0, 
                     $this->GetPointDate($m[5], $m[6], $m[7], $m[8], $refDate, $lastMonth, $lastDay)];
                     
        if (preg_match("/([x]{17})(.)(.)(.)(.)/", $text, $m) == 1 && $this->refDate === null)
            $this->GetPointDate($m[2], $m[3], $m[4], $m[5], $refDate, $lastMonth, $lastDay);
            
        return null;
    }

    private function GetPointDate($rDay, $rMonth, $rHour, $rMin, $refDate, &$lastMonth, &$lastDay)
    {
        if ($rMonth != '.')
            $month = self::a64toi($rMonth);
        else
        {
            if (self::a64toi($rDay) >= $lastDay)
                $month = $lastMonth;
            else
            {
                $month = $lastMonth + 1;
                if ($month > 12)	// todo если >= 12  то почему 1, а не 0 ?
                    $month = 1;
            }
            // todo log month fix
        }

        if ($rDay != '.')
            $day = self::a64toi($rDay);
        else
            $day = $lastDay;

        $dayOfYear = gmdate("z", gmmktime(0, 0, 0, $month, $day, gmdate("Y", $this->time))); // +- 1 високосный день не важен
        $epochYear = $this->GetEpochYear($dayOfYear);

        $res = gmmktime(self::a64toi($rHour), self::a64toi($rMin), 0, $month, $day, $epochYear);
        $this->CheckDate($refDate, $res);
        if ($this->refDate === null) {
        	echo "rday: $rDay, rmon: $rMonth, rH: '$rHour', rMin: '$rMin', day: $day, month: $month, y: $epochYear " .date('c', $res). "<br/>";
            $this->refDate = $res;
        }

        $lastDay = $day;
        $lastMonth = $month;

        return $res;
    }

    protected function ProcessActivity($refDay)
    {
        $this->activity = [];

        if ($refDay == null)
        {
            $this->SetError("Message processing failed: no points: no reference date in activity!");
            return;
        }

        $dText = mb_substr($this->ActivityText, 1, 1);
        $day = self::a64toi($dText);
        /*if ($day === false || $day > 31)
        {
            $this->SetError("Wrong day '$day' in activity '$dText', '{$this->ActivityText}'");
            return;
        }*/

        $refTime = time();
        $tm = self::GetActivityBaseDate($this->time, $refDay, $day);
        echo date('c', $tm) . " ref: " . date('c', $refDay). "day: $day, dtext: '$dText'<br/>";
        for ($hour = 0; $hour < 24; $hour++)
        {
            $shift = $hour < $this->RotateHour ? 24*3600 : 0;
            $hourActivity = self::a64toi(mb_substr($this->ActivityText, $hour +1, 1));

            echo " $hour: $hourActivity <br/>";
            for ($i = 5; $i >= 0; $i--)
            {
                $pTime = $tm + $hour * 3600 + $i * 10 * 60 + $shift;
                $this->CheckDate($refTime, $pTime, 'activity');
                $this->activity[] = [$pTime, $hourActivity % 2]; // todo: так, или развернуть
                $hourActivity /= 2;
            }
            // $Result = $Result . reverse (main::itoaz ( main::a64toi(substr ($self->{activity},$i+1,1)), 2, 6)) . "\t".$Hour."\n";
        }
    }

    public static function itoaz($val, $radix, $len)
    {
        $res = "";
        for ($i = 0; $i < $len; $i++)
        {
            $c = mb_substr("0123456789ABCDEF", $val % $radix, 1);
            $res = $c.$res;
            $val /= $radix;
        }
        return $res;
    }
}
?>
