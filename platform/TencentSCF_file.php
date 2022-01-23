<?php
// https://cloud.tencent.com/document/product/583/33846
// https://cloud.tencent.com/document/product/583/18581
// https://cloud.tencent.com/document/product/583/18580

function printInput($event, $context)
{
    if (strlen(json_encode($event['body']))>500) $event['body']=substr($event['body'],0,strpos($event['body'],'base64')+30) . '...Too Long!...' . substr($event['body'],-50);
    echo urldecode(json_encode($event, JSON_PRETTY_PRINT)) . '
 
' . urldecode(json_encode($context, JSON_PRETTY_PRINT)) . '
 
';
}

function GetGlobalVariable($event)
{
    $_GET = $event['queryString'];
    $postbody = explode("&",$event['body']);
    foreach ($postbody as $postvalues) {
        $pos = strpos($postvalues,"=");
        $_POST[urldecode(substr($postvalues,0,$pos))]=urldecode(substr($postvalues,$pos+1));
    }
    $cookiebody = explode("; ",$event['headers']['cookie']);
    foreach ($cookiebody as $cookievalues) {
        $pos = strpos($cookievalues,"=");
        $_COOKIE[urldecode(substr($cookievalues,0,$pos))]=urldecode(substr($cookievalues,$pos+1));
    }
}

function GetPathSetting($event, $context)
{
    $_SERVER['firstacceptlanguage'] = strtolower(splitfirst(splitfirst($event['headers']['accept-language'],';')[0],',')[0]);
    $_SERVER['function_name'] = $context['function_name'];
    $_SERVER['namespace'] = $context['namespace'];
    $_SERVER['Region'] = $context['tencentcloud_region'];
    $host_name = $event['headers']['host'];
    $_SERVER['HTTP_HOST'] = $host_name;
    $serviceId = $event['requestContext']['serviceId'];
    if ( $serviceId === substr($host_name,0,strlen($serviceId)) ) {
        $_SERVER['base_path'] = '/'.$event['requestContext']['stage'].'/'.$_SERVER['function_name'].'/';
        $path = substr($event['path'], strlen('/'.$_SERVER['function_name'].'/'));
    } else {
        $_SERVER['base_path'] = $event['requestContext']['path'];
        $path = substr($event['path'], strlen($event['requestContext']['path']));
    }
    //$_SERVER['PHP_SELF'] = path_format($_SERVER['base_path'] . $path);
    $_SERVER['REMOTE_ADDR'] = $event['requestContext']['sourceIp'];
    $_SERVER['HTTP_X_REQUESTED_WITH'] = $event['headers']['x-requested-with'];
    $_SERVER['HTTP_USER_AGENT'] = $event['headers']['user-agent'];
    if (isset($event['headers']['authorization'])) {
        $basicAuth = splitfirst(base64_decode(splitfirst($event['headers']['authorization'], 'Basic ')[1]), ':');
        $_SERVER['PHP_AUTH_USER'] = $basicAuth[0];
        $_SERVER['PHP_AUTH_PW'] = $basicAuth[1];
    }
    $_SERVER['REQUEST_SCHEME'] = $event['headers']['x-api-scheme'];
    //$_SERVER['REQUEST_SCHEME'] = $event['headers']['x-forwarded-proto'];
    $_SERVER['host'] = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'];
    $_SERVER['referhost'] = explode('/', $event['headers']['referer'])[2];
    $_SERVER['HTTP_TRANSLATE'] = $event['headers']['translate'];//'f'
    $_SERVER['HTTP_IF_MODIFIED_SINCE'] = $event['headers']['if-modified-since'];
    $_SERVER['USER'] = 'qcloud';
    return $path;
}

function getConfig($str, $disktag = '')
{
    //include 'config.php';
    $s = file_get_contents('.data/config.php');
    //$configs = substr($s, 18, -2);
    $configs = '{' . splitlast(splitfirst($s, '{')[1], '}')[0] . '}';
    if ($configs!='') {
        $envs = json_decode($configs, true);
        if (isInnerEnv($str)) {
            if ($disktag=='') $disktag = $_SERVER['disktag'];
            if (isset($envs[$disktag][$str])) {
                if (isBase64Env($str)) return base64y_decode($envs[$disktag][$str]);
                else return $envs[$disktag][$str];
            }
        } else {
            if (isset($envs[$str])) {
                if (isBase64Env($str)) return base64y_decode($envs[$str]);
                else return $envs[$str];
            }
        }
    }
    return '';
}

function setConfig($arr, $disktag = '')
{
    if ($disktag=='') $disktag = $_SERVER['disktag'];
    //include 'config.php';
    $s = file_get_contents('.data/config.php');
    //$configs = substr($s, 18, -2);
    $configs = '{' . splitlast(splitfirst($s, '{')[1], '}')[0] . '}';
    if ($configs!='') $envs = json_decode($configs, true);
    $disktags = explode("|",getConfig('disktag'));
    $indisk = 0;
    $operatedisk = 0;
    foreach ($arr as $k => $v) {
        if (isCommonEnv($k)) {
            if (isBase64Env($k)) $envs[$k] = base64y_encode($v);
            else $envs[$k] = $v;
        } elseif (isInnerEnv($k)) {
            if (isBase64Env($k)) $envs[$disktag][$k] = base64y_encode($v);
            else $envs[$disktag][$k] = $v;
            $indisk = 1;
        } elseif ($k=='disktag_add') {
            array_push($disktags, $v);
            $operatedisk = 1;
        } elseif ($k=='disktag_del') {
            $disktags = array_diff($disktags, [ $v ]);
            $envs[$v] = '';
            $operatedisk = 1;
        } elseif ($k=='disktag_copy') {
            $newtag = $v . '_' . date("Ymd_His");
            $envs[$newtag] = $envs[$v];
            array_push($disktags, $newtag);
            $operatedisk = 1;
        } elseif ($k=='disktag_rename' || $k=='disktag_newname') {
            if ($arr['disktag_rename']!=$arr['disktag_newname']) $operatedisk = 1;
        } else {
            $envs[$k] = $v;
        }
    }
    if ($indisk) {
        $diskconfig = $envs[$disktag];
        $diskconfig = array_filter($diskconfig, 'array_value_isnot_null');
        ksort($diskconfig);
        $envs[$disktag] = $diskconfig;
    }
    if ($operatedisk) {
        if (isset($arr['disktag_newname']) && $arr['disktag_newname']!='') {
            $tags = [];
            foreach ($disktags as $tag) {
                if ($tag==$arr['disktag_rename']) array_push($tags, $arr['disktag_newname']);
                else array_push($tags, $tag);
            }
            $envs['disktag'] = implode('|', $tags);
            $envs[$arr['disktag_newname']] = $envs[$arr['disktag_rename']];
            $envs[$arr['disktag_rename']] = '';
        } else {
            $disktags = array_unique($disktags);
            foreach ($disktags as $disktag) if ($disktag!='') $disktag_s .= $disktag . '|';
            if ($disktag_s!='') $envs['disktag'] = substr($disktag_s, 0, -1);
            else $envs['disktag'] = '';
        }
    }
    $envs = array_filter($envs, 'array_value_isnot_null');
    //ksort($envs);
    $response = updateEnvironment($envs, $_SERVER['function_name'], $_SERVER['Region'], $_SERVER['namespace'], getConfig('SecretId'), getConfig('SecretKey'));
    WaitSCFStat($_SERVER['function_name'], $_SERVER['Region'], $_SERVER['namespace'], getConfig('SecretId'), getConfig('SecretKey'));
    return $response;
}

function WaitSCFStat($function_name, $Region, $Namespace, $SecretId, $SecretKey)
{
    $trynum = 0;
    while( json_decode(getfunctioninfo($function_name, $Region, $Namespace, $SecretId, $SecretKey),true)['Response']['Status']!='Active' ) echo '
'.++$trynum;
}

function install()
{
    global $constStr;
    if ($_GET['install2']) {
        $tmp['admin'] = $_POST['admin'];
        $response = setConfigResponse( setConfig($tmp) );
        if (api_error($response)) {
            $html = api_error_msg($response);
            $title = 'Error';
            return message($html, $title, 201);
        }
        if (needUpdate()) {
            OnekeyUpate();
            return message('update to github version, reinstall.
        <script>
            var expd = new Date();
            expd.setTime(expd.getTime()+(2*60*60*1000));
            var expires = "expires="+expd.toGMTString();
            document.cookie=\'language=; path=/; \'+expires;
        </script>
        <meta http-equiv="refresh" content="3;URL=' . $url . '">', 'Program updating', 201, 1);
        }
        return message(getconstStr('Success') . '
    <script>
        var expd = new Date();
        expd.setTime(expd.getTime()+(2*60*60*1000));
        var expires = "expires="+expd.toGMTString();
        document.cookie=\'language=; path=/; \'+expires;
        var i = 0;
        var uploadList = setInterval(function(){
            if (document.getElementById("dis").style.display=="none") {
                console.log(i++);
            } else {
                clearInterval(uploadList);
                location.href = "' . path_format($_SERVER['base_path'] . '/') . '";
            }
        }, 1000);
    </script>', 201, 1);
    }
    if ($_GET['install1']) {
        $tmp['timezone'] = $_COOKIE['timezone'];
        $SecretId = $_POST['SecretId'];
        $tmp['SecretId'] = $SecretId;
        $SecretKey = $_POST['SecretKey'];
        $tmp['SecretKey'] = $SecretKey;
        $tmp['ONEMANAGER_CONFIG_SAVE'] = $_POST['ONEMANAGER_CONFIG_SAVE'];
        $response = json_decode(SetbaseConfig($tmp, $_SERVER['function_name'], $_SERVER['Region'], $_SERVER['namespace'], $SecretId, $SecretKey), true)['Response'];
        if (api_error($response)) {
            $html = api_error_msg($response);
            $title = 'Error';
            return message($html, $title, 201);
        } else {
            if ($tmp['ONEMANAGER_CONFIG_SAVE'] != 'file') {
                $html = getconstStr('ONEMANAGER_CONFIG_SAVE_ENV') . '<br><a href="' . $_SERVER['base_path'] . '">' . getconstStr('Home') . '</a>';
                $title = 'Reinstall';
                return message($html, $title, 201, 1);
            }
            $html .= '
    <form action="?install2" method="post" onsubmit="return notnull(this);">
        <label>'.getconstStr('SetAdminPassword').':<input name="admin" type="password" placeholder="' . getconstStr('EnvironmentsDescription')['admin'] . '" size="' . strlen(getconstStr('EnvironmentsDescription')['admin']) . '"></label><br>
        <input type="submit" value="'.getconstStr('Submit').'">
    </form>
    <script>
        function notnull(t)
        {
            if (t.admin.value==\'\') {
                alert(\''.getconstStr('SetAdminPassword').'\');
                return false;
            }
            return true;
        }
    </script>';
            $title = getconstStr('SetAdminPassword');
            return message($html, $title, 201, 1);
        }
    }
    if ($_GET['install0']) {
        $html .= '
    <form action="?install1" method="post" onsubmit="return notnull(this);">
language:<br>';
        foreach ($constStr['languages'] as $key1 => $value1) {
            $html .= '
        <label><input type="radio" name="language" value="'.$key1.'" '.($key1==$constStr['language']?'checked':'').' onclick="changelanguage(\''.$key1.'\')">'.$value1.'</label><br>';
        }
        //if (getConfig('SecretId')==''||getConfig('SecretKey')=='') 
        $html .= '
        <a href="https://console.cloud.tencent.com/cam/capi" target="_blank">'.getconstStr('Create').' SecretId & SecretKey</a><br>
        <label>SecretId:<input name="SecretId" type="text" placeholder="" size=""></label><br>
        <label>SecretKey:<input name="SecretKey" type="password" placeholder="" size=""></label><br>';
        $html .= '
        <label><input type="radio" name="ONEMANAGER_CONFIG_SAVE" value="" ' . ('file'==getenv('ONEMANAGER_CONFIG_SAVE')?'':'checked') . '>' . getconstStr('ONEMANAGER_CONFIG_SAVE_ENV') . '</label><br>
        <label><input type="radio" name="ONEMANAGER_CONFIG_SAVE" value="file" ' . ('file'==getenv('ONEMANAGER_CONFIG_SAVE')?'checked':'') . '>' . getconstStr('ONEMANAGER_CONFIG_SAVE_FILE') . '</label><br>';
        $html .= '
        <input type="submit" value="'.getconstStr('Submit').'">
    </form>
    <script>
        var nowtime= new Date();
        var timezone = 0-nowtime.getTimezoneOffset()/60;
        var expd = new Date();
        expd.setTime(expd.getTime()+(2*60*60*1000));
        var expires = "expires="+expd.toGMTString();
        document.cookie="timezone="+timezone+"; path=/; "+expires;
        function changelanguage(str)
        {
            var expd = new Date();
            expd.setTime(expd.getTime()+(2*60*60*1000));
            var expires = "expires="+expd.toGMTString();
            document.cookie=\'language=\'+str+\'; path=/; \'+expires;
            location.href = location.href;
        }
        function notnull(t)
        {';
        //if (getConfig('SecretId')==''||getConfig('SecretKey')=='') 
        $html .= '
            if (t.SecretId.value==\'\') {
                alert(\'input SecretId\');
                return false;
            }
            if (t.SecretKey.value==\'\') {
                alert(\'input SecretKey\');
                return false;
            }';
        $html .= '
            return true;
        }
    </script>';
        $title = getconstStr('SelectLanguage');
        return message($html, $title, 201);
    }
    $html .= '<a href="?install0">'.getconstStr('ClickInstall').'</a>, '.getconstStr('LogintoBind');
    $title = 'Install';
    return message($html, $title, 201);
}

function post2url($url, $data)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $response = curl_exec($ch);
    curl_close($ch);
    //echo $response;
    return $response;
}

function ReorganizeDate($arr)
{
    $str = '';
    ksort($arr);
    foreach ($arr as $k1 => $v1) {
        $str .= '&' . $k1 . '=' . $v1;
    }
    $str = substr($str, 1); // remove first '&'. 去掉第一个&
    return $str;
}

function getfunctioninfo($function_name, $Region, $Namespace, $SecretId, $SecretKey)
{
    //$meth = 'GET';
    $meth = 'POST';
    $host = 'scf.tencentcloudapi.com';
    $tmpdata['Action'] = 'GetFunction';
    $tmpdata['FunctionName'] = $function_name;
    $tmpdata['Namespace'] = $Namespace;
    $tmpdata['Nonce'] = time();
    $tmpdata['Region'] = $Region;
    $tmpdata['SecretId'] = $SecretId;
    $tmpdata['Timestamp'] = time();
    $tmpdata['Token'] = '';
    $tmpdata['Version'] = '2018-04-16';
    $data = ReorganizeDate($tmpdata);
    $signStr = base64_encode(hash_hmac('sha1', $meth.$host.'/?'.$data, $SecretKey, true));
    //echo urlencode($signStr);
    //return file_get_contents('https://'.$url.'&Signature='.urlencode($signStr));
    return post2url('https://'.$host, $data.'&Signature='.urlencode($signStr));
}

function getfunctioncodeurl($function_name, $Region, $Namespace, $SecretId, $SecretKey)
{
    //$meth = 'GET';
    $meth = 'POST';
    $host = 'scf.tencentcloudapi.com';
    $tmpdata['Action'] = 'GetFunctionAddress';
    $tmpdata['FunctionName'] = $function_name;
    $tmpdata['Namespace'] = $Namespace;
    $tmpdata['Nonce'] = time();
    $tmpdata['Region'] = $Region;
    $tmpdata['SecretId'] = $SecretId;
    $tmpdata['Timestamp'] = time();
    $tmpdata['Token'] = '';
    $tmpdata['Version'] = '2018-04-16';
    $data = ReorganizeDate($tmpdata);
    $signStr = base64_encode(hash_hmac('sha1', $meth.$host.'/?'.$data, $SecretKey, true));
    //echo urlencode($signStr);
    //return file_get_contents('https://'.$url.'&Signature='.urlencode($signStr));
    return post2url('https://'.$host, $data.'&Signature='.urlencode($signStr));
}

function copyFolder($from, $to)
{
    if (substr($from, -1)=='/') $from = substr($from, 0, -1);
    if (substr($to, -1)=='/') $to = substr($to, 0, -1);
    if (!file_exists($to)) mkdir($to, 0777);
    $handler=opendir($from);
    while($filename=readdir($handler)) {
        if($filename != '.' && $filename != '..'){
            $fromfile = $from.'/'.$filename;
            $tofile = $to.'/'.$filename;
            if(is_dir($fromfile)){// 如果读取的某个对象是文件夹，则递归
                copyFolder($fromfile, $tofile);
            }else{
                copy($fromfile, $tofile);
            }
        }
    }
    closedir($handler);
    return 1;
}

function updateEnvironment($Envs, $function_name, $Region, $Namespace, $SecretId, $SecretKey)
{
    sortConfig($Envs);
    // 获取当前代码并解压
    //$codeurl = json_decode(getfunctioncodeurl($function_name, $Region, $Namespace, $SecretId, $SecretKey), true)['Response']['Url'];
    //$codezip = '/tmp/oldcode.zip';
    $outPath = '/tmp/code/';
    
    //file_put_contents($codezip, file_get_contents($codeurl));
    //$zip=new ZipArchive();
    //$zip->open($codezip);
    //$html = $zip->extractTo($outPath);

    $coderoot = __DIR__ . '/../';
    copyFolder($coderoot, $outPath);
    
    // 将配置写入
    $prestr = '<?php $configs = \'' . PHP_EOL;
    $aftstr = PHP_EOL . '\';';
    file_put_contents($outPath . '.data/config.php', $prestr . json_encode($Envs, JSON_PRETTY_PRINT) . $aftstr);

    // 将目录中文件打包成zip
    $source = '/tmp/code.zip';
    //$zip=new ZipArchive();
    $zip=new PharData($source);
    //if($zip->open($source, ZipArchive::CREATE)){
        addFileToZip($zip, $outPath); //调用方法，对要打包的根目录进行操作，并将ZipArchive的对象传递给方法
    //    $zip->close(); //关闭处理的zip文件
    //}

    return updateProgram($function_name, $Region, $namespace, $SecretId, $SecretKey, $source);
    $tmp1['Response']['Error']['Message'] = $codeurl;
    error_log1($tmp1['Response']['Error']['Message']);
    return json_encode($tmp1);
}

function SetbaseConfig($Envs, $function_name, $Region, $Namespace, $SecretId, $SecretKey)
{
    if ($Envs['ONEMANAGER_CONFIG_SAVE'] == 'file') $tmp_env = Array( 'ONEMANAGER_CONFIG_SAVE' => 'file' );
    else {
        $Envs['ONEMANAGER_CONFIG_SAVE'] == '';
        $tmp = json_decode(getfunctioninfo($function_name, $Region, $Namespace, $SecretId, $SecretKey),true)['Response']['Environment']['Variables'];
        foreach ($tmp as $tmp1) {
            $tmp_env[$tmp1['Key']] = $tmp1['Value'];
        }
        foreach ($Envs as $key1 => $value1) {
            $tmp_env[$key1] = $value1;
        }
        $tmp_env = array_filter($tmp_env, 'array_value_isnot_null'); // remove null. 清除空值
        ksort($tmp_env);
    }

    $i = 0;
    foreach ($tmp_env as $key1 => $value1) {
        $tmpdata['Environment.Variables.'.$i.'.Key'] = $key1;
        $tmpdata['Environment.Variables.'.$i.'.Value'] = $value1;
        $i++;
    }

    $meth = 'POST';
    $host = 'scf.tencentcloudapi.com';
    $tmpdata['Action'] = 'UpdateFunctionConfiguration';
    $tmpdata['FunctionName'] = $function_name;
    $tmpdata['Namespace'] = $Namespace;
    $tmpdata['Nonce'] = time();
    $tmpdata['Region'] = $Region;
    $tmpdata['SecretId'] = $SecretId;
    $tmpdata['Timestamp'] = time();
    $tmpdata['Token'] = '';
    $tmpdata['Version'] = '2018-04-16';
    $tmpdata['Description'] = 'Onedrive index and manager in Tencent SCF.';
    $tmpdata['MemorySize'] = 64;
    $tmpdata['Timeout'] = 30;
    $data = ReorganizeDate($tmpdata);
    //echo $data;
    $signStr = base64_encode(hash_hmac('sha1', $meth.$host.'/?'.$data, $SecretKey, true));
    //echo urlencode($signStr);
    $response = post2url('https://'.$host, $data.'&Signature='.urlencode($signStr));
    if (api_error(setConfigResponse($response))) {
        return $response;
    }
    if ( json_decode(getfunctioninfo($function_name, $Region, $Namespace, $SecretId, $SecretKey),true)['Response']['Timeout'] < 25 ) {
        $tmp['Response']['Error']['Message'] = 'Operating, please try again.';
        $tmp['Response']['Error']['Code'] = 'Retry';
        return json_encode($tmp);
    }
    WaitSCFStat($function_name, $Region, $Namespace, $SecretId, $SecretKey);

    $s = file_get_contents('.data/config.php');
    //$configs = substr($s, 18, -2);
    $configs = '{' . splitlast(splitfirst($s, '{')[1], '}')[0] . '}';
    if ($configs!='') $envs = json_decode($configs, true);
    foreach ($Envs as $k => $v) {
        $envs[$k] = $v;
    }
    $envs = array_filter($envs, 'array_value_isnot_null');
    //ksort($envs);
    $response = updateEnvironment($envs, $function_name, $Region, $Namespace, $SecretId, $SecretKey);
    return $response;
}

function updateProgram($function_name, $Region, $Namespace, $SecretId, $SecretKey, $source)
{
    $secretId = $SecretId;
    $secretKey = $SecretKey;
    $host = 'scf.tencentcloudapi.com';
    $service = "scf";
    $version = "2018-04-16";
    $action = "UpdateFunctionCode";
    $region = $Region;
    $timestamp = time();
    $algorithm = "TC3-HMAC-SHA256";

    // step 1: build canonical request string
    $httpRequestMethod = "POST";
    $canonicalUri = "/";
    $canonicalQueryString = "";
    $canonicalHeaders = "content-type:application/json; charset=utf-8\n"."host:".$host."\n";
    $signedHeaders = "content-type;host";

    //$tmpdata['Action'] = 'UpdateFunctionCode';
    $tmpdata['Code']['ZipFile'] = base64_encode( file_get_contents($source) );
    $tmpdata['CodeSource'] = 'ZipFile';
    $tmpdata['FunctionName'] = $function_name;
    $tmpdata['Handler'] = 'index.main_handler';
    //$tmpdata['Namespace'] = $Namespace;
    //$tmpdata['Nonce'] = time();
    //$tmpdata['Region'] = $Region;
    //$tmpdata['SecretId'] = $SecretId;
    //$tmpdata['Timestamp'] = time();
    //$tmpdata['Token'] = '';
    //$tmpdata['Version'] = '2018-04-16';
    $payload = json_encode($tmpdata);
    //$payload = '{"Limit": 1, "Filters": [{"Values": ["\u672a\u547d\u540d"], "Name": "instance-name"}]}';
    $hashedRequestPayload = hash("SHA256", $payload);
    $canonicalRequest = $httpRequestMethod."\n"
        .$canonicalUri."\n"
        .$canonicalQueryString."\n"
        .$canonicalHeaders."\n"
        .$signedHeaders."\n"
        .$hashedRequestPayload;
    //echo $canonicalRequest.PHP_EOL;

    // step 2: build string to sign
    $date = gmdate("Y-m-d", $timestamp);
    $credentialScope = $date."/".$service."/tc3_request";
    $hashedCanonicalRequest = hash("SHA256", $canonicalRequest);
    $stringToSign = $algorithm."\n"
        .$timestamp."\n"
        .$credentialScope."\n"
        .$hashedCanonicalRequest;
    //echo $stringToSign.PHP_EOL;

    // step 3: sign string
    $secretDate = hash_hmac("SHA256", $date, "TC3".$secretKey, true);
    $secretService = hash_hmac("SHA256", $service, $secretDate, true);
    $secretSigning = hash_hmac("SHA256", "tc3_request", $secretService, true);
    $signature = hash_hmac("SHA256", $stringToSign, $secretSigning);
    //echo $signature.PHP_EOL;

    // step 4: build authorization
    $authorization = $algorithm
        ." Credential=".$secretId."/".$credentialScope
        .", SignedHeaders=content-type;host, Signature=".$signature;
    //echo $authorization.PHP_EOL;

    //$curl = "curl -X POST https://".$host
    //    .' -H "Authorization: '.$authorization.'"'
    //    .' -H "Content-Type: application/json; charset=utf-8"'
    //    .' -H "Host: '.$host.'"'
    //    .' -H "X-TC-Action: '.$action.'"'
    //    .' -H "X-TC-Timestamp: '.$timestamp.'"'
    //    .' -H "X-TC-Version: '.$version.'"'
    //    .' -H "X-TC-Region: '.$region.'"'
    //    ." -d '".$payload."'";
    //error_log1( $curl.PHP_EOL );
    //return '{"response": {"Error": {"Message":"' . $curl . '"}}}';
    $headers['Authorization'] = $authorization;
    $headers['Content-Type'] = 'application/json; charset=utf-8';
    $headers['Host'] = $host;
    $headers['X-TC-Action'] = $action;
    $headers['X-TC-Timestamp'] = $timestamp;
    $headers['X-TC-Version'] = $version;
    $headers['X-TC-Region'] = $region;
    return curl('POST', 'https://'.$host, $payload, $headers)['body'];
}

function api_error($response)
{
    return isset($response['Error']);
    return ($response==null)||isset($response['Error']);
}

function api_error_msg($response)
{
    return $response['Error']['Code'] . '<br>
' . $response['Error']['Message'] . '<br><br>
function_name:' . $_SERVER['function_name'] . '<br>
Region:' . $_SERVER['Region'] . '<br>
namespace:' . $_SERVER['namespace'] . '<br>
<button onclick="location.href = location.href;">'.getconstStr('Refresh').'</button>';
}

function setConfigResponse($response)
{
    return json_decode( $response, true )['Response'];
}

function OnekeyUpate($GitSource = 'Github', $auth = 'qkqpttgf', $project = 'OneManager-php', $branch = 'master')
{
    $source = '/tmp/code.zip';
    $outPath = '/tmp/';

    if ($GitSource=='Github') {
        // 从github下载对应tar.gz，并解压
        $url = 'https://github.com/' . $auth . '/' . $project . '/tarball/' . urlencode($branch) . '/';
    } elseif ($GitSource=='HITGitlab') {
        $url = 'https://git.hit.edu.cn/' . $auth . '/' . $project . '/-/archive/' . urlencode($branch) . '/' . $project . '-' . urlencode($branch) . '.tar.gz';
    } else return json_encode(['Response'=>['Error'=>['code'=>'Git Source input Error!']]]);
    $tarfile = '/tmp/github.tar.gz';
    file_put_contents($tarfile, file_get_contents($url));
    $phar = new PharData($tarfile);
    $html = $phar->extractTo($outPath, null, true);//路径 要解压的文件 是否覆盖

    // 获取解压出的目录名
    $outPath = findIndexPath($outPath);

    // 放入配置文件
    file_put_contents($outPath . '/.data/config.php', file_get_contents(__DIR__ . '/../.data/config.php'));

    // 将目录中文件打包成zip
    //$zip=new ZipArchive();
    $zip=new PharData($source);
    //if($zip->open($source, ZipArchive::CREATE)){
        addFileToZip($zip, $outPath); //调用方法，对要打包的根目录进行操作，并将ZipArchive的对象传递给方法
    //    $zip->close(); //关闭处理的zip文件
    //}

    return updateProgram($_SERVER['function_name'], $_SERVER['Region'], $_SERVER['namespace'], getConfig('SecretId'), getConfig('SecretKey'), $source);
}

function addFileToZip($zip, $rootpath, $path = '')
{
    if (substr($rootpath,-1)=='/') $rootpath = substr($rootpath, 0, -1);
    if (substr($path,0,1)=='/') $path = substr($path, 1);
    $handler=opendir(path_format($rootpath.'/'.$path)); //打开当前文件夹由$path指定。
    while($filename=readdir($handler)){
        if($filename != "." && $filename != ".."){//文件夹文件名字为'.'和‘..’，不要对他们进行操作
            $nowname = path_format($rootpath.'/'.$path."/".$filename);
            if(is_dir($nowname)){// 如果读取的某个对象是文件夹，则递归
                $zip->addEmptyDir($path."/".$filename);
                addFileToZip($zip, $rootpath, $path."/".$filename);
            }else{ //将文件加入zip对象
                $newname = $path."/".$filename;
                if (substr($newname,0,1)=='/') $newname = substr($newname, 1);
                $zip->addFile($nowname, $newname);
                //$zip->renameName($nowname, $newname);
            }
        }
    }
    @closedir($handler);
}

function WaitFunction() {
    if ( json_decode(getfunctioninfo($_SERVER['function_name'], $_SERVER['Region'], $_SERVER['namespace'], getConfig('SecretId'), getConfig('SecretKey')),true)['Response']['Status']=='Active' ) return true;
    else return false;
}

function changeAuthKey() {
    if ($_POST['SecretId']!=''&&$_POST['SecretId']!='') {
        $tmp['SecretId'] = $_POST['SecretId'];
        $tmp['SecretKey'] = $_POST['SecretKey'];
        $response = setConfigResponse( SetbaseConfig($tmp, $_SERVER['function_name'], $_SERVER['Region'], $_SERVER['namespace'], $tmp['SecretId'], $tmp['SecretKey']) );
        if (api_error($response)) {
            $html = api_error_msg($response);
            $title = 'Error';
            return message($html, $title, 400);
        } else {
            $html = getconstStr('Success') . '
    <script>
        var status = "' . $response['DplStatus'] . '";
        var i = 0;
        var uploadList = setInterval(function(){
            if (document.getElementById("dis").style.display=="none") {
                console.log(i++);
            } else {
                clearInterval(uploadList);
                location.href = "' . path_format($_SERVER['base_path'] . '/') . '";
            }
        }, 1000);
    </script>';
            return message($html, $title, 201, 1);
        }
    }
    $html = '
    <form action="" method="post" onsubmit="return notnull(this);">
        <a href="https://console.cloud.tencent.com/cam/capi" target="_blank">' . getconstStr('Create') . ' SecretId & SecretKey</a><br>
        <label>SecretId:<input name="SecretId" type="text" placeholder="" size=""></label><br>
        <label>SecretKey:<input name="SecretKey" type="password" placeholder="" size=""></label><br>
        <input type="submit" value="' . getconstStr('Submit') . '">
    </form>
    <script>
        function notnull(t)
        {
            if (t.SecretId.value==\'\') {
                alert(\'input SecretId\');
                return false;
            }
            if (t.SecretKey.value==\'\') {
                alert(\'input SecretKey\');
                return false;
            }
            return true;
        }
    </script>';
    return message($html, 'Change platform Auth token or key', 200);
}
