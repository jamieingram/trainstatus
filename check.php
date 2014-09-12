<?php
include('config/settings.php');
require 'PushBullet.class.php';

if (empty($token)) {
    die("please create a config file, and include a value for \$token");
}
if (empty($start)) $start = "IPS";
if (empty($end)) $end = "LST";

$wsdl_url = "https://realtime.nationalrail.co.uk/ldbws/wsdl.aspx";
$basicNamespace_str = "http://thalesgroup.com/RTTI/2010-11-01/ldb/commontypes";

/* queries to run:
arriving at IPS coming from LST
arriving at LST coming from IPS
departing from IPS calling at LST
departing from LST stopping at IPS
*/

global $client;
global $db;
global $db_available;
global $push_bullet;
global $push_bullet_enabled;
global $devices_array;

$queries_array = array();
array_push($queries_array, new TrainQuery(TrainQuery::$QUERY_METHOD_ARRIVAL, $start, $end, TrainQuery::$QUERY_TYPE_FROM));
array_push($queries_array, new TrainQuery(TrainQuery::$QUERY_METHOD_ARRIVAL, $end, $start, TrainQuery::$QUERY_TYPE_FROM));
array_push($queries_array, new TrainQuery(TrainQuery::$QUERY_METHOD_DEPARTURE, $start, $end, TrainQuery::$QUERY_TYPE_TO));
array_push($queries_array, new TrainQuery(TrainQuery::$QUERY_METHOD_DEPARTURE, $end, $start, TrainQuery::$QUERY_TYPE_TO));

$client = new SoapClient($wsdl_url, array("trace" => 1, "exception" => 0));

$push_bullet_enabled = true;

if ($push_bullet_enabled) {
    $push_bullet = new PushBullet($push_bullet_token);
    $devices = $push_bullet->getDevices();
    $devices = $devices->devices;
    $devices_array = array();
    for ($i=0; $i < count($devices); $i++) {
        $device = $devices[$i];
        array_push($devices_array, $device->iden);
    }
}

try {
    $db = new mysqli($mysql_host, $mysql_user, $mysql_password, $mysql_db);

    // Check connection
    if ($mysqli->connect_errno) {
        echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
    }else{
        $db_available = true;
    }
} catch (Exception $e) {
    echo "Cannot connected to database.";
}

$auth = new stdClass();
$auth->TokenValue = $token;

$header = new SoapHeader($basicNamespace_str, 'AccessToken', $auth, false);

$client->__setSoapHeaders($header);

function obj2array($obj) {
    $out = array();
    foreach ($obj as $key => $val) {
        switch(true) {
            case is_object($val):
                $out[$key] = obj2array($val);
            break;
            case is_array($val):
                $out[$key] = obj2array($val);
            break;
            default:
            $out[$key] = $val;
        }
    }
    return $out;
}

class TrainQuery {

    public static $QUERY_METHOD_ARRIVAL = 'GetArrivalBoard';
    public static $QUERY_METHOD_DEPARTURE = 'GetDepartureBoard';
    public static $QUERY_TYPE_TO = 'to';
    public static $QUERY_TYPE_FROM = 'from';

    public $method;
    public $crs;
    public $crsFilter;
    public $filterType;

    function __construct($method, $crs, $crsFilter, $filterType) {
        $this->method = $method;
        $this->crs = $crs;
        $this->crsFilter = $crsFilter;
        $this->filterType = $filterType;
    }
}

class TrainService {

    public static $EMPTY_DATE = '0000-00-00 00:00:00';

    public $serviceID;
    public $location;
    public $from_station;
    public $from_code;
    public $to_station;
    public $to_code;
    public $sta;
    public $eta;
    public $ata;
    public $std;
    public $etd;
    public $atd;
    public $platform;
    public $isCancelled;
    public $overdueMessage;
    public $distruptionReason;

    public $isDelayed;
    public $delayLength;
    public $creationDate;
    public $notificationSent;
    public $lastUpdated;

    function __construct($location_str) {
        $this->location = $location_str;

    }

    function parseService($service, $method) {
        global $client;

        $this->from_station = $service['origin']['location']['locationName'];
        $this->from_code = $service['origin']['location']['crs'];
        $this->to_station = $service['destination']['location']['locationName'];
        $this->to_code = $service['destination']['location']['crs'];
        $this->sta = $service['sta'];
        $this->eta = $service['eta'];
        $this->std = $service['std'];
        $this->etd = $service['etd'];
        $this->serviceID = $service['serviceID'];
        $this->platform = $service['platform'];

        //
        if (!empty($this->serviceID)) {
            $result = $client->__soapCall('GetServiceDetails', array('parameters' => array('serviceID' => $this->serviceID)));
            $result_array = obj2array($result);
            $result_array = $result_array['GetServiceDetailsResult'];
            $this->ata = $result_array['ata'];
            $this->ata = $result_array['atd'];
            if ($result_array['isCancelled'] == "1") {
                $this->isCancelled = true;
            }
            
            $this->overdueMessage = $result_array['overdueMessage'];
            $this->distruptionReason = $result_array['distruptionReason'];
        }

        if ($method == TrainQuery::$QUERY_METHOD_ARRIVAL) {
            //this is an arrival board service
            $estimated = $this->eta;
            if (!empty($this->ata)) $estimated = $this->ata;
            $scheduled = $this->sta;
        }else{
            //this is a departure board service
            $estimated = $this->etd;
            $scheduled = $this->std;
        }

        switch ($estimated) {
            case "On time":
                //do nothing - the train is currently on time
                $this->isDelayed = false;
            break;
            case "Delayed":
                //no more data is available - mark as delayed
                $this->isDelayed = true;
            break;
            case "Cancelled":
                $this->isCancelled = true;
            break;
            default:
                $this->isDelayed = true;
                //assume this is a time - calculate the difference between this and the sta
                $scheduled_array = split(':',$scheduled);
                $estimated_array = split(':',$estimated);
                if (count($estimated_array) == 2) {
                    $scheduled_array[0] = intval($scheduled_array[0]);
                    $scheduled_array[1] = intval($scheduled_array[1]);
                    $estimated_array[0] = intval($estimated_array[0]);
                    $estimated_array[1] = intval($estimated_array[1]);
                    if ($estimated_array[0] - $scheduled_array[0] < -1) {
                        //the train is arriving after midnight - add 24 hours for calculation
                        $estimated_array[0] += 24;
                    }
                    $diff = ($estimated_array[0] * 60 + $estimated_array[1]) - ($scheduled_array[0] * 60 + $scheduled_array[1]);
                    $this->delayLength = $diff;
                }
            break;
        }
    }
    //
    function save() {
        global $db;
        $serviceID = $db->real_escape_string($this->serviceID);
        $row = $db->query("select * from service where serviceID='".$serviceID."'");
        if ($row->num_rows == 0) {
            //insert a new row
            $query = "insert into service set ";
        }else{
            //update the row
            $row_array = $row->fetch_array(MYSQLI_ASSOC);
            $this->creationDate = $row_array['creationDate'];
            if ($row_array['notificationSent'] != TrainService::$EMPTY_DATE) {
                $this->notificationSent = $row_array['notificationSent'];
            }
            $this->lastUpdated = $row_array['lastUpdated'];
            //
            $query = "update service set ";
        }

        if (empty($this->creationDate) || $this->creationDate == TrainService::$EMPTY_DATE) {
            $this->creationDate = date("Y-m-d H:i:s");
        }

        foreach($this as $key => $value) {
            $query .= $key."='".$db->real_escape_string($value)."',";
        }

        $query = substr($query, 0, strlen($query) - 1);
        
        if ($row->num_rows != 0) {
            $query .= " where serviceID='".$serviceID."'";
        }
        
        if (!$result = $db->query($query)) {
            printf("Error message: %s\n", $db->error);
        }
        //
        $row = $db->query("select * from service where serviceID='".$serviceID."'");
        $row_array = $row->fetch_array(MYSQLI_ASSOC);
        $this->creationDate = $row_array['creationDate'];
        $this->lastUpdated = $row_array['lastUpdated'];
    }
    //
    function check_notification() {
        global $devices_array;
        global $push_bullet_enabled;
        global $push_bullet;

        $now = new DateTime();

        if ($this->creationDate != TrainService::$EMPTY_DATE) {
            $creationDate = new DateTime($this->creationDate);
            $diff = $now->diff($creationDate);
            if ($diff->h > 4) return;
        }

        if ($this->notificationSent != TrainService::$EMPTY_DATE) {
            $notificationSent = new DateTime($this->notificationSent);
            $diff = $now->diff($notificationSent);
            if (($diff->h * 60) + $diff->i < 10) return;
        }

        //check to see if we should send a notification
        if ($this->isCancelled || $this->delayLength > 10) {
            if (!empty($this->sta)) {
                //this is an arrival service
                $body = $this->from_code." - ".$this->to_code.". Due to arrive ".$this->sta;
            }else{
                $body = $this->from_code." - ".$this->to_code.". Due to depart ".$this->std;
            }
            if (!empty($this->platform)) {
                " on platform ".$this->platform;
            }
            $body .= ".";

            if ($this->isCancelled) {
                $title = 'train cancelled';
                $body .= "Train is cancelled.";
            }else if ($this->delayLength > 10) {
                $title = 'train delayed';
                $body .= "Delayed by ".$this->delayLength;
            }
            //
            if ($push_bullet_enabled) {
                foreach ($devices_array as $key => $value) {
                    $push_bullet->pushNote($value, $title, $body);
                }
                $this->notificationSent = date("Y-m-d H:i:s");
                $this->save();
            }
        }
    }
}


function parseService($service, $method, $location_str) {
    $train_service = new TrainService($location_str);
    $train_service->parseService($service, $method);
    $train_service->save();
    $train_service->check_notification();
}

for ($i = 0; $i < count($queries_array); $i++) { 
    
    $query = $queries_array[$i];

    $parameters = array(
        'numRows' => 30,
        'crs' => $query->crs,
        'filterCrs' => $query->crsFilter,
        'filterType' => $query->filterType
    );

    try {
        $result = $client->__soapCall($query->method, array('parameters' => $parameters));
        $result_array = obj2array($result);
        $result_array = $result_array[$query->method . 'Result'];
        //parse the result looking for delays
        $location_str = $result_array['locationName'];
        if (!is_null($result_array['trainServices']['service'])) {
            $services_array = $result_array['trainServices']['service'];

            if (is_null($services_array[0])) {
                parseService($services_array, $query->method, $location_str);
            }else{
                for ($j = 0; $j < count($services_array) ; $j++) {
                    $service = $services_array[$j];
                    parseService($service, $query->method, $location_str);
                }
            }
            //
        }
    } catch (Exception $e) {
        echo "REQUEST:\n" . $client->__getLastRequest() . "\n";
    }
}
?>