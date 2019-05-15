<?php

use WHMCS\Database\Capsule as Capsule; 

require_once("Cache.php");

Class RequestSRS
{
	protected $account;
	protected $base_url;
	public $params;
	protected $sessionId;
	public $domainName;

  /**
   * Request constructor.
   * @param $params
   * @throws Exception
   */
	public function __construct($params)
	{
    $this->params = $params;
    if(is_object($params["domainObj"])) $this->domainName = $params["domainObj"]->getDomain(TRUE);
    if($this->params["API_key"]) $this->account = trim($this->params["API_key"]);
    if($this->params["Base_URL"]) $this->base_url = trim($this->params["Base_URL"]);
		if($this->account == '') throw new Exception('Missing API key');
		if($this->params['Base_URL']=='') $this->base_url = API_HOST;
    logModuleCall(
      'nameSRS',
      'request',
      $params,
      ''
    );
	}

  /**
   * @param $action - either GET or POST
   * @param $functionName - API endpoint (the path after API_HOST)
   * @param $myParams - array with the API parameters
   * @return array
   * @throws Exception
   */
	public function request($action, $functionName, $myParams)
	{
		$this->sessionId = SessionCache::get($this->account);
		if ($this->sessionId == "")
		{
		  // probably we have not been logged-in before
      $loginResult = $this->call('GET','/authenticate/login/'.$this->account, array());
			if($loginResult["code"] == 1000)
      {
        $this->sessionId = $loginResult['parameters']['token'];
        SessionCache::put($this->sessionId, $this->account);
      }
			elseif($loginResult["code"] == 2200)
			{
			  throw new Exception('Invalid API key');
			}
			else
			{
			  throw new Exception($loginResult['desc']!='' ? $loginResult['desc'] : 'Unknown login error');
			}
		}
		$result = $this->call($action, $functionName,$myParams);
    if ($result['code'] == 1000 OR $result['code'] == 1300) return $result;
    elseif ($result['code'] == 2200)
    {
      // session token has expired - get a new one
      SessionCache::clear($this->account);
      $loginResult = $this->call('GET','/authenticate/login/'.$this->account, array());
      if($loginResult["code"] == 1000)
      {
        $this->sessionId = $loginResult['parameters']['token'];
        SessionCache::put($this->sessionId, $this->account);
      }
      elseif($loginResult["code"] == 2200)
      {
        throw new Exception('Could not renew the session token for the API');
      }
      else
      {
        throw new Exception($loginResult['desc']!='' ? $loginResult['desc'] : 'Unknown login error');
      }
      $result = $this->call($action, $functionName,$myParams);
      if ($result['code'] == 1000 OR $result['code'] == 1300) return $result;
      else throw new Exception('('.$result['code'].') '.$result['desc']);
    }
    else throw new Exception('('.$result['code'].') '.$result['desc']);
	}

  /**
   * Make external API call to registrar API.
   *
   * @param string $action - GET or POST
   * @param string $functionName - API endpoint
   * @param array $postfields
   *
   * @throws Exception Connection error
   * @throws Exception Bad API response
   *
   * @return array
   */
  private function call($action, $functionName, $postfields)
  {
		$url = 'https://'.$this->base_url.$functionName.($this->sessionId!='' ? '/'.$this->sessionId : '');
    $ch = curl_init();
    curl_setopt_array($ch, Array(
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_SSL_VERIFYPEER => 1,
      CURLOPT_TIMEOUT => 16
    ));
    if(is_array($postfields))
    {
      // converts indexed field names into array syntax (e.g. "ns[2]" becomes "ns[]")
      $query = preg_replace('/%5B[0-9]+%5D=/simU', '%5B%5D=', http_build_query($postfields,'x_','&',PHP_QUERY_RFC3986));
    }
    else $query = $postfields;
    if(strtoupper($action) == 'GET')
    {
      curl_setopt($ch, CURLOPT_URL, $url.'?'.$query);
    }
    else
    {
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    }

    $response = curl_exec($ch);
    if (curl_errno($ch)) throw new Exception('Connection Error: ' . curl_errno($ch) . ' - ' . curl_error($ch));
    curl_close($ch);
    $result = json_decode($response,TRUE);
    logModuleCall(
      'nameSRS',
      $functionName,
      $postfields,
      $response,
      $result
    );
    if ($result === null && json_last_error() !== JSON_ERROR_NONE) throw new Exception('Bad response received from API');
    return $result;
  }

  /**
   * @return array
   * @throws Exception
   */
  public function searchDomain()
  {
    /**
     * @var PDO
     */
    $pdo = Capsule::connection()->getPdo(); 

    $domain = DomainCache::get($this->domainName);
    if(is_array($domain)) return $domain;

    $result = $pdo->query('SELECT namesrs_id FROM tblnamesrshandles WHERE type = 1 AND whmcs_id = '.$this->params['domainid']);
    if($result->rowCount()) $handle = $result->fetch(PDO::FETCH_NUM)[0];
    else
    {
      $list = $this->request('GET',"/domain/domainlist",Array('domainname' => $this->domainName));
      if($list)
      {
        $handle = $list['items'][0]['itemID'];
      }
      else throw new Exception('Could not retrieve domain ID from the API');
    }
    $result = $this->request('GET',"/domain/domaindetails", Array('itemid' => $handle));
    $domain = $result['items'][$handle];
    DomainCache::put($domain);
    // store the mapping between WHMCS domainID and NameISP domainHandle
    $pdo->query('INSERT INTO tblnamesrshandles(whmcs_id,type,namesrs_id) VALUES('.$this->params['domainid'].',1,'.$handle.') ON DUPLICATE KEY UPDATE namesrs_id = VALUES(namesrs_id)');
    return $domain;
  }

  /**
   * @param int $type - 2 (renew domain), 3 (transfer domain), 4 (register domain)
   * @param int $domain_id - WHMCS domainID
   * @param int $reqid - ID of the API request in NameSRS
   * @param string $json - only used when registering a domain, to store NameServers
   */
  public function queueRequest($type, $domain_id, $reqid, $json = "")
  {
    /**
     * @var PDO
     */
    $pdo = Capsule::connection()->getPdo(); 

    try
    {
      $stm = $pdo->prepare('INSERT INTO tblnamesrsjobs(last_id,order_id,method,request,response) VALUES(:dom_id,:req_id,:type,:json,"")');
      $stm->execute(Array('dom_id' => $domain_id, 'req_id' => $reqid, 'type' => $type, 'json' => $json));
    }
    catch (Exception $e)
    {
      logModuleCall(
        'nameSRS',
        'queueRequest',
        Array('type' => $type, 'domain_id' => $domain_id, 'req_id' => $reqid, 'json' => json_decode($json,TRUE)),
        $e->getMessage()
      );
    }
  }
}

?>
