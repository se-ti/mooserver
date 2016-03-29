<?
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

	var $CompressionFactor;
	var $CompressionType;
	
	var $TestValue;
	var $TestValue2;
	
	var $IsOk;
	var $ErrorMessage;
	
	var $RotateHour;
	
	const TECH_HEADER_LENGTH = 6;
	const ACTIVITY_LENGTH = 25;
	const POINTS_HEADER_LENGTH = 12;
	const PROPRIETARYBASE64_INDEX = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz&#";

	
	function CMooseSMS($text, $time = null)
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
		
		$this->points = array();
		$this->activity = null;
		
		$this->RotateHour = 17;
		
		$this->TestValue = "Pre";
		$this->TestValue2 = '';
		//$this->TestValue = CMooseSMS::a64toi ("9");

		$this->ProcessSMSText ();
		
	}

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
	}

	protected function ProcessSMSText ()
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

		$this->ProcessTechHeader ();
		$this->ProcessPointsHeader ();
		if ( $this->IsOk )
			$this->ProcessPointsArray ();
		if ( $this->IsOk )
		$this->ProcessActivity ();
	}
	
	protected function ProcessTechHeader ()
	{
		$this->id = CMooseSMS::a64bitstoi ( $this->TechHeaderText, 30, 6 );
		$this->gsmTries = CMooseSMS::a64bitstoi ( $this->TechHeaderText, 24, 6 );
		$this->gpsOn = CMooseSMS::a64bitstoi ( $this->TechHeaderText, 12, 12 );
		$this->volt = CMooseSMS::a64bitstoi ( $this->TechHeaderText, 6, 6 );
		$this->temp = CMooseSMS::a64bitstoi ( $this->TechHeaderText, 0, 6 );
		
		if ( $this->id === FALSE || $this->gsmTries === FALSE || $this->gpsOn ===FALSE ||
			 $this->volt === FALSE || $this->temp === FALSE )
		{
			$this->SetError ( 'Message processing failed (internal error in tech header routine)!' );
			return;
		}	 
		
		$this->volt = $this->volt*0.01 + 3.20;
		
		if ( $this->temp > 30 )
			$this->temp -= 65;
	}
	
	protected function ProcessPointsHeader ()
	{
		$EpochYear = date("Y", $this->time);
		
		$LatDegree = CMooseSMS::a64bitstoi ( $this->PointsHeaderText , 59, 7 );
		$LatPartsOfDegree = CMooseSMS::a64bitstoi ( $this->PointsHeaderText , 43, 16 );
		$LongDegree = CMooseSMS::a64bitstoi ( $this->PointsHeaderText , 35, 8 );
		$LongPartsOfDegree = CMooseSMS::a64bitstoi ( $this->PointsHeaderText , 20, 15 );
		
		$DayOfYear = CMooseSMS::a64bitstoi ( $this->PointsHeaderText , 11, 9 );
		$TimeOfDayIn10MinIntervals = CMooseSMS::a64bitstoi ( $this->PointsHeaderText , 3, 8 );
		$this->CompressionFactor = CMooseSMS::a64bitstoi ( $this->PointsHeaderText , 66, 4 );
		$this->CompressionType = CMooseSMS::a64bitstoi ( $this->PointsHeaderText , 70, 2 );
		
		
		if ( $LatDegree === NULL || $LatPartsOfDegree === NULL || $LongDegree === NULL ||
			 $LongPartsOfDegree === NULL || $DayOfYear === NULL || $TimeOfDayIn10MinIntervals === NULL ||
			 $this->CompressionType === NULL || $this->CompressionFactor === NULL )
		{
			$this->SetError ( 'Message processing failed (internal error in points header routine)!' );
			return;
		}
		
		if ( $DayOfYear > 365 - 14 && date("m", $this->time) < 2) 
			$EpochYear--; 	// The message does not carry a year, which it's generated in.
							// If the message is received in the beginning of an year, but was sent
							// in the end of an year, mostly probably it was the previous year.
		
		if ( $LatDegree == 0 && $LatPartsOfDegree==0 && $LongDegree==0 && $LongPartsOfDegree==0 &&
		      $DayOfYear==0 && $TimeOfDayIn10MinIntervals==0 ) // This is a 'null point'
			  {
			  $this->SetError ( "There are no any meaning points in the message." );
			  return;
			  }
		
		$NewPoint[0] = $LatDegree + $LatPartsOfDegree/65536;
		$NewPoint[1] = $LongDegree + $LongPartsOfDegree/32768;				
		$NewPoint[2] =	gmmktime ( 0, 0, 0, 1, 1, $EpochYear ) + 
						($DayOfYear-1) * 24 * 60 * 60 +
						 $TimeOfDayIn10MinIntervals * 60 * 10;
		//$this->TestValue2 = CMooseSMS::a64bitstoi ( $this->PointsHeaderText , 35, 8 );
		
		
		$this->points[] = $NewPoint;
	}
	
	protected function ProcessPointsArray ()
	{
		if ( $this->CompressionType != 3 )
		{
			$this->SetError ( "Unsupported compression: $this->CompressionFactor/$this->CompressionType" );
			return;
		}
		
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
			
		$XFieldCapacity=1<<$XFieldLength;
		$YFieldCapacity=1<<$YFieldLength;
		
		//$this->TestValue2 .= " Kfactor=$Kfactor, XFieldCapacity=$XFieldCapacity, YFieldCapacity=$YFieldCapacity";
		
		for ( $i = 0 ; $i+$PointLength <= strlen ( $this->PointsArrayText ) ; $i+=$PointLength )
		{
			$PointText = substr ( $this->PointsArrayText, $i, $PointLength );
			$X = CMooseSMS::a64bitstoi ( $PointText, $dTimeLength+$YFieldLength, $XFieldLength );
			$Y = CMooseSMS::a64bitstoi ( $PointText, $dTimeLength, $YFieldLength );
			$dTime = CMooseSMS::a64bitstoi ( $PointText, 0, $dTimeLength );
			
			if ( $X === NULL || $Y===NULL || $dTime===NULL)
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
					
			if ( $X > -$XFieldCapacity/2+$XFieldCapacity/80 && $X < $XFieldCapacity/2-$XFieldCapacity/80 &&
				 $Y > -$YFieldCapacity/2+$YFieldCapacity/80 && $Y < $YFieldCapacity/2-$YFieldCapacity/80 ) 
			{	// If the value is close to the range edge, it's most probably invalid.
				$Point[0]=$this->points[0][0] + $dLat;
				$Point[1]=$this->points[0][1] + $dLong;
				$Point[2]=$this->points[0][2] + $dTime*$dTimeTick;
			
				$this->points[] = $Point;
			}
		}
	}
	
	protected function ProcessActivity ()
	{
		$this->TestValue2 .= $this->ActivityText;
		$this->TestValue2 .= '.';
		
		$ActivityDay = CMooseSMS::a64bitstoi ( $this->ActivityText, 24*6 , 6 );
		
		if ( $ActivityDay === NULL )
		{
			$this->SetError ( "Message processing failed (internal error in activity routine)!" );
			return;
		}
		
		if ( $this->points[0][2] != 0 )
		{
			$ActivityDate1 = gmmktime ( 0, 0, 0, gmdate ('n', $this->points[0][2]), $ActivityDay, gmdate ('Y', $this->points[0][2]) );
			$ActivityDate2 =  $ActivityDate1 + 24 * 60 * 60;
		}
		else
		{
			$this->SetError ( "Message has no valid date" );
			return;
		}
		
		for ( $i=0 ; $i<24 ; $i++ )
			for ( $j=0 ; $j<6 ; $j++ )
			{
				$CurrentActivity[1] = CMooseSMS::a64bitstoi ( $this->ActivityText, (23-$i)*6+$j , 1 );
				$CurrentActivity[0] = (($i < $this->RotateHour ) ? $ActivityDate2 : $ActivityDate1 ) + $i*60*60 + $j*10*60;
				if ( $CurrentActivity[1] === NULL )
				{
					$this->SetError ( "Message processing failed (internal error in activity routine)!" );
					return;
				}
				$this->activity[] = $CurrentActivity;
				$this->TestValue2 .= $CurrentActivity[1];
			}
	}
	
	public static function a64toi ($aStr)
	{
		$Result=0;
		$StringLength = strlen ( $aStr );
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
	
		$Result >>= $aStartBit%6;
		$Result &= (1<<($aNBits))-1;
		
		return $Result;
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
	
	public function GetErrorMessage ()
	{
	return $this->ErrorMessage;
	}
}
?>
