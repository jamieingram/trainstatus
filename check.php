<?php
include('config/settings.php');
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

$queries_array = array();
array_push($queries_array, new TrainQuery(TrainQuery::$QUERY_METHOD_ARRIVAL, $start, $end, TrainQuery::$QUERY_TYPE_FROM));
array_push($queries_array, new TrainQuery(TrainQuery::$QUERY_METHOD_ARRIVAL, $end, $start, TrainQuery::$QUERY_TYPE_FROM));
array_push($queries_array, new TrainQuery(TrainQuery::$QUERY_METHOD_DEPARTURE, $start, $end, TrainQuery::$QUERY_TYPE_TO));
array_push($queries_array, new TrainQuery(TrainQuery::$QUERY_METHOD_DEPARTURE, $end, $start, TrainQuery::$QUERY_TYPE_TO));

$client = new SoapClient($wsdl_url, array("trace" => 1, "exception" => 0));

try {
    $db = new mysqli($mysql_host, $mysql_user, $mysql_password, $mysql_db);

    // Check connection
    if ($mysqli->connect_errno) {
        echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
    }else{
        $db_available = true;
        echo "db connected";
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

    public $serviceID;
    public $location;
    public $from;
    public $from_code;
    public $to;
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

    function __construct($location_str) {
        $this->location = $location_str;

    }

    function parseService($service, $method) {
        global $client;

        $this->from = $service['origin']['location']['locationName'];
        $this->from_code = $service['origin']['location']['crs'];
        $this->to = $service['destination']['location']['locationName'];
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
                    if ($estimated_array[0] < $scheduled_array[0]) {
                        //the train is arriving after midnight - add 24 hours for calculation
                        $estimated_array[0] += 24;
                    }
                    $diff = ($estimated_array[0] * 60 + $estimated_array[1]) - ($scheduled_array[0] * 60 + $scheduled_array[1]);
                    $this->delayLength = $diff; 
                }
            break;
        }
        if ($this->isCancelled) {
            print_r($this);
        }
        if ($this->delayLength > 30) {
            print_r($this);
        }
    }
    //
    function save() {
        foreach($this as $key => $value) {
           //$row = mysql
       }
    }
    //
    function notify() {

    }
}


function parseService($service, $method, $location_str) {
    $train_service = new TrainService($location_str);
    $train_service->parseService($service, $method);
    $train_service->save();
    $train_service->notify();
    return $train_service;

}

$trains_array = array();

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
                $train_service = parseService($services_array, $query->method, $location_str);
                array_push($trains_array, $train_service);
            }else{
                for ($j = 0; $j < count($services_array) ; $j++) {
                    $service = $services_array[$j];
                    $train_service = parseService($service, $query->method, $location_str);
                    array_push($trains_array, $train_service);
                }
            }
            //
        }
    } catch (Exception $e) {
        echo "REQUEST:\n" . $client->__getLastRequest() . "\n";
    }
}
?>