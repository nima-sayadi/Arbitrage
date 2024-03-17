<?php

    /*
        A small part of an exclusive arbitrage application written in pure PHP
        By Nima Sayadi
        This script is only a demonstration of my coding capablities and is not supposed to be the full functioning script.
        The full script is much larger and it is private.
    */

    class Kucoin extends Arbitrage{

        private $base_url = "https://api.kucoin.com";
        private $key = "";
        private $secret = "";

        private $class = "Kucoin";

        private function nonce() : mixed{
            return time()*1000;
        }

        private function passphrase() {
            $passphrase = ""; // ENTER passphrase HERE
            return base64_encode(hash_hmac("sha256", $passphrase, $this->secret, true));
        }

        private function signature($request_path = '', $body = '' , $timestamp , $method = 'GET') {
            $body = is_array($body) ? json_encode($body) : $body;
            $timestamp = floor(microtime(true) * 1000);
            $payload = $timestamp . $method . $request_path . $body;
            return base64_encode(hash_hmac("sha256", $payload, $this->secret, true));
        }

        /**
         * handle errors from the exchanger api
         * ***
         * 
         * Returns an array or "ok" string
        */        
        private function handleResponse(mixed $response , string $func_name , string $class_name) : mixed{
            if(is_array($response)){
                $httpCode = $response["httpCode"];
                unset($response["httpCode"]);
            }
            else{
                $httpCode = $response->httpCode;
                unset($response->httpCode);
            }
            if(count((array)$response) === 0){
                $code = "unknown";
                $status = "empty";
                $msg = "Response Returned Empty";
                $res = [
                    "class" => $class_name ,
                    "function" => $func_name ,
                    "status" => $status,
                    "code" => $code,
                    "msg" => $msg
                ];
            }
            else{
                if($httpCode == 200){
                    $res = "ok";
                }
                elseif($httpCode == 429 || $httpCode == 403){
                    echo "\n\nRequest Limit Reached. Script will continue in 10min !\n\n";
                    sleep(600);
                    $status = "limited";
                    $code = $httpCode;
                    $msg = "Request Limit Reached";
                    $res = [
                        "class" => $class_name ,
                        "function" => $func_name ,
                        "status" => $status,
                        "code" => $code,
                        "msg" => $msg
                    ];
                }
                else{
                    $status = "error";
                    if(property_exists($response,"code")){
                        $code = $response->code;
                        $msg = $response->msg;
                    }
                    else{
                        $code = $httpCode;
                        $msg = "No message";
                    }
                    $res = [
                        "class" => $class_name ,
                        "function" => $func_name ,
                        "status" => $status,
                        "code" => "$code",
                        "msg" => $msg
                    ];
                }
            }
            if($this->stmt != null && $res != "ok"){
                $stmt = $this->stmt;
                $sql = "INSERT INTO error_logs(class,`function`,`status`,code,msg,`date`) VALUES(?,?,?,?,?,?)";
                if(!mysqli_stmt_prepare($stmt , $sql)){
                    echo "SQLNotPrepare";
                }
                else{
                    $date = $this->create_date(time());
                    mysqli_stmt_bind_param($stmt , "ssssss" , $class_name , $func_name , $status , $code , $msg , $date);
                    mysqli_stmt_execute($stmt);
                }
            }
            return $res;
        }

        /**
         * Get the order book of a Currency-USDT
         * ***
         * 
         * Returns $res = ["asks" => array , "bids" => array]
        */
        public function getBook(string $currency,bool $limit = true) : array{
            $symbol = "$currency-USDT";
            $time = time()*1000;
            $request_path = "/api/v1/market/orderbook/level2_20?symbol=$symbol";
            $full_path = $this->base_url . $request_path;
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                $asks = array_slice($info->data->asks,0,-15);
                $bids = array_slice($info->data->bids,0,-15);
                if($limit == false){
                    $res = [
                        "class" => $this->class ,
                        "function" => __FUNCTION__ ,
                        "status" => "success",
                        "code" => "200",
                        "asks" => $asks,
                        "bids" => $bids
                    ];
                }
                else{
                    $res = [
                        "class" => $this->class ,
                        "function" => __FUNCTION__ ,
                        "status" => "success",
                        "code" => "200",
                        "asks" => [$asks[0][0],$asks[0][1]],
                        "bids" => [$bids[0][0],$bids[0][1]]
                    ];
                }
            }
            return $res;
        }
        /**
         * Get existance of the currency
         * ***
         * 
         * ex : $currency = "1INCH"
         * 
         * Returns $res = ["exists" => bool , chains => array , "deposit" => bool , "withdraw" => bool];
        */
        public function currencyAvailable(string $currency,string $chain = "coin") : array{
            $request_path = "/api/v3/currencies/$currency";
            $full_path = $this->base_url . $request_path;
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                $cond = false;
                $deposit = null;
                $withdraw = null;
                $arr = [];
                if($chain == "all"){
                    $coin_id = strtolower($currency);
                    foreach($info->data->chains as $value){
                        if($value->chainId == $coin_id && $value->isWithdrawEnabled == true &&  $value->isDepositEnabled == true){
                            $cond = true;
                            $deposit = true;
                            $withdraw = true;
                            array_push($arr,"coin");
                        }
                        elseif($value->chainId == "bsc" && $value->isWithdrawEnabled == true &&  $value->isDepositEnabled == true){
                            $cond = true;
                            $deposit = true;
                            $withdraw = true;
                            array_push($arr,"bep20");
                        }
                        elseif($value->chainId == "trx" && $value->isWithdrawEnabled == true &&  $value->isDepositEnabled == true){
                            $cond = true;
                            $deposit = true;
                            $withdraw = true;
                            array_push($arr,"trc20");
                        }
                    }
                }
                else{
                    if($chain == "bep20"){
                        $chain_id = "bsc";
                    }
                    elseif($chain == "trc20"){
                        $chain_id = "trx";
                    }
                    else{
                        $chain_id = strtolower($currency);
                    }
                    foreach($info->data->chains as $value){
                        if($value->chainId == $chain_id && $value->isWithdrawEnabled == true &&  $value->isDepositEnabled == true){
                            $cond = true;
                            $deposit = true;
                            $withdraw = true;
                            if($chain_id == strtolower($currency)){
                                array_push($arr,"coin");
                            }
                            else{
                                array_push($arr,strtolower($value->chainName));
                            }
                            break;
                        }
                    }
                }
                $res = [
                    "class" => $this->class ,
                    "function" => __FUNCTION__ ,
                    "status" => "success",
                    "code" => "200",
                    "exists" => $cond,
                    "chains" => $arr,
                    "deposit" => $deposit,
                    "withdraw" => $withdraw
                ];
            }
            return $res;
        }
        /**
         * Get currency withdraw fee in the currency value
         * ***
         * 
         * Returns $res = ["fee" => string , "min_withdraw" => string , "base_currency" => string];
        */
        public function getWithdrawFee(string $currency, string $chain = "coin", string $amount = "") : array{
            $request_path = "/api/v3/currencies/$currency";
            $full_path = $this->base_url . $request_path;
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                if($chain == "bep20"){
                    $chain_id = "bsc";
                }
                elseif($chain == "trc20"){
                    $chain_id = "trx";
                }
                else{
                    $chain_id = strtolower($currency);
                }
                foreach($info->data->chains as $value){
                    if($value->chainId == $chain_id){
                        $fee = $value->withdrawalMinFee;
                        $min_withdraw = $value->withdrawalMinSize;
                        break;
                    }
                }
                $res = [
                    "class" => $this->class ,
                    "function" => __FUNCTION__ ,
                    "status" => "success",
                    "code" => "200",
                    "fee" => $fee ,
                    "min_withdraw" => $min_withdraw ,
                    "base_currency" => $currency
                ];
            }
            return $res;
        }
        /**
         * Get currency pair taker fee in demical form of the total amount of trading value e.g. BTC (Taker fee)
         * ***
         * 
         * Returns $res = ["buy_fee" => string , "sell_fee" => string] | 0.001 = 0.1% => 0.001 * 100
        */
        public function getTradeFee(string $currency) : array{
            $request_path = "/api/v1/trade-fees?symbols=$currency-USDT";
            $full_path = $this->base_url . $request_path;
            $nonce = $this->nonce();
            $headers = [
                "KC-API-TIMESTAMP: " . $nonce ,
                "KC-API-KEY: " . $this->key ,
                "KC-API-PASSPHRASE: " . $this->passphrase() ,
                "KC-API-SIGN: " . $this->signature($request_path,"",$nonce) ,
                "KC-API-KEY-VERSION: 2"
            ];
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                if(is_array($info->data)){
                    $fee = $info->data[0]->takerFeeRate;
                }
                else{
                    $fee = $info->data->takerFeeRate;
                }
                $res = [
                    "class" => $this->class ,
                    "function" => __FUNCTION__ ,
                    "status" => "success",
                    "code" => "200",
                    "buy_fee" => $fee,
                    "sell_fee" => $fee
                ];
            }
            return $res;
        }
        /**
         * Get Deposit Address
         * ***
         * 
         * Returns $res = ["chain" => string , "address" => string , "memo" => string]
        */
        public function getDepositAddress(string $currency,string $chain = "coin") : array{
            if($chain == "trc20" || $chain == "bep20"){
                $request_path = "/api/v1/deposit-addresses?currency=$currency&chain=" . strtoupper($chain);
            }
            else{
                $request_path = "/api/v1/deposit-addresses?currency=$currency";
            }
            $full_path = $this->base_url . $request_path;
            $nonce = $this->nonce();
            $headers = [
                "KC-API-TIMESTAMP: " . $nonce ,
                "KC-API-KEY: " . $this->key ,
                "KC-API-PASSPHRASE: " . $this->passphrase() ,
                "KC-API-SIGN: " . $this->signature($request_path,"",$nonce) ,
                "KC-API-KEY-VERSION: 2"
            ];
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                $data = $info;
                if(empty($data) || empty($data->address)){
                    $request_path = "/api/v1/deposit-addresses";
                    $full_path = $this->base_url . $request_path;
                    $body = [
                        "currency" => $currency
                    ];
                    if($chain == "trc20" || $chain == "bep20"){
                        $body["chain"] = strtoupper($chain);
                    }
                    $nonce = $this->nonce();
                    $headers = [
                        "KC-API-TIMESTAMP: " . $nonce ,
                        "KC-API-KEY: " . $this->key ,
                        "KC-API-PASSPHRASE: " . $this->passphrase() ,
                        "KC-API-SIGN: " . $this->signature($request_path,$body,$nonce,"POST") ,
                        "KC-API-KEY-VERSION: 2"
                    ];
                    $curl = curl_init($full_path);
                    curl_setopt($curl, CURLOPT_POST, 1);
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    $info2 = curl_exec($curl);
                    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    curl_close($curl);
                    $info2 = json_decode($info2);
                    $info2->httpCode = $httpCode;
                    $res2 = $this->handleResponse($info2,__FUNCTION__,$this->class);
                    if($res2 == "ok"){
                        $address = $data->address;
                        if(property_exists($data,"memo")){
                            $tag = $data->memo;
                        }
                    }
                }
                else{
                    $address = $data->address;
                    if(property_exists($data,"memo")){
                        $tag = $data->memo;
                    }
                }
                $res = [
                    "class" => $this->class ,
                    "function" => __FUNCTION__ ,
                    "status" => "success",
                    "code" => "200",
                    "chain" => $chain,
                    "address" => $address,
                    "memo" => $tag
                ];
            }
            return $res;
        }
        /**
         * Get The Current Balance of a Currency in Wallet
         * ***
         * 
         * Returns $res = ["balance" => string]
        */
        public function getBalance(string $currency , string $type = "wallet") : array{
            if($type == "wallet"){
                $type = "MAIN";
            }
            else{
                $type = "TRADE";
            }
            $request_path = "/api/v1/accounts/transferable?currency=$currency&type=$type";
            $full_path = $this->base_url . $request_path;
            $nonce = $this->nonce();
            $headers = [
                "KC-API-TIMESTAMP: " . $nonce ,
                "KC-API-KEY: " . $this->key ,
                "KC-API-PASSPHRASE: " . $this->passphrase() ,
                "KC-API-SIGN: " . $this->signature($request_path,"",$nonce) ,
                "KC-API-KEY-VERSION: 2"
            ];
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                $balance = $info->transferable;
                $res = [
                    "class" => $this->class ,
                    "function" => __FUNCTION__ ,
                    "status" => "success",
                    "code" => "200",
                    "balance" => $balance
                ];
            }
            return $res;
        }
        /** 
         * Get order details
         * ***
         * 
         * states : partially_filled,filled,canceled,partially_canceled
         * ***
         * 
         * Returns $res = ["order_id" => string , "state" => string , "executed_base" => string , "executed_quote" => string 
         * , "amount_base" => string , "amount_quote" => string , "total_avg_price" => string , "fee" => string , "fee_currency" => string]
        */
        public function getOrder(string $order_id, string $currency = "") : array{
            $request_path = "/api/v1/orders/$order_id";
            $full_path = $this->base_url . $request_path;
            $nonce = $this->nonce();
            $headers = [
                "KC-API-TIMESTAMP: " . $nonce ,
                "KC-API-KEY: " . $this->key ,
                "KC-API-PASSPHRASE: " . $this->passphrase() ,
                "KC-API-SIGN: " . $this->signature($request_path,"",$nonce) ,
                "KC-API-KEY-VERSION: 2"
            ];
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                if($info->isActive == false){
                    $state = "filled";
                }
                elseif($info->cancelExist != false){
                    $state = "canceled";
                }
                else{
                    $state = "partially_filled";
                }
                $original_state = $state;
                $executed_base = $info->dealSize;
                $executed_quote = $info->dealFunds;
                $amount_base = $info->size;
                $amount_quote = $info->funds;
                $fee = $info->fee;
                $fee_currency = $info->feeCurrency;
                $total_avg_price = $info->price;
                $class_name = $this->class;
                $res = [
                    "class" => $this->class ,
                    "function" => __FUNCTION__ ,
                    "status" => "success" ,
                    "code" => "200" ,
                    "order_id" => $order_id ,
                    "state" => $state ,
                    "executed_base" => $executed_base ,
                    "executed_quote" => $executed_quote ,
                    "amount_base" => $amount_base ,
                    "amount_quote" => $amount_quote ,
                    "total_avg_price" => $total_avg_price ,
                    "fee" => $fee ,
                    "fee_currency" => $fee_currency
                ];
                $created_date = $this->create_date($info->createdAt,true);
                $updated_date = $created_date;
                $updated_timestamp = substr($info->createdAt,0,-3);
                if($this->stmt != null){
                    $stmt = $this->stmt;
                    $sql = "INSERT INTO orders(exchanger,symbol,`state`,original_state,executed_base,executed_quote,amount_base,amount_quote
                    ,total_avg_price,fee,fee_currency,order_id,created_date,updated_date,updated_timestamp) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                    if(!mysqli_stmt_prepare($stmt , $sql)){
                        echo "\n\n------ ATTENTION !! SQL Not Prepared in $class_name class & ".__FUNCTION__." function !! ATTENTION ------\n\n";
                        // To implement an SMS panel or any kind of notifications for the developer such as Telegram,Bale etc.
                    }
                    else{
                        mysqli_stmt_bind_param($stmt , "sssssssssssssss" , $class_name , $symbol , $state , $original_state , $executed_base , $executed_quote
                        , $amount_base , $amount_quote , $total_avg_price ,$fee , $fee_currency , $order_id , $created_date , $updated_date , $updated_timestamp);
                        mysqli_stmt_execute($stmt);
                    }
                }
                else{
                    // To implement an SMS panel or any kind of notifications for the developer such as Telegram,Bale etc.
                }
            }
            return $res;
        }
        /**
         * Submit order
         * ***
         * 
         * $side should be "buy" or "sell"
         * ***
         * 
         * Returns $res = ["order_id" => string]
        */
        public function submitOrder(string $currency,string $side,string $amount,string $amount_usdt) : array{
            $request_path = "/api/v1/orders";
            $full_path = $this->base_url . $request_path;
            $body = [
                "clientOid" => $this->newToken(16) ,
                "side" => $side ,
                "symbol" => $currency . "-USDT" ,
                "type" => "market" ,
                "size" => $amount // ATTENTION, param "funds" can be used for $amount_usdt instead //
            ];
            $nonce = $this->nonce();
            $headers = [
                "KC-API-TIMESTAMP: " . $nonce ,
                "KC-API-KEY: " . $this->key ,
                "KC-API-PASSPHRASE: " . $this->passphrase() ,
                "KC-API-SIGN: " . $this->signature($request_path,$body,$nonce,"POST") ,
                "KC-API-KEY-VERSION: 2"
            ];
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                $res = [
                    "class" => $this->class ,
                    "function" => __FUNCTION__ ,
                    "status" => "success" ,
                    "code" => "200" ,
                    "order_id" => $info->orderId
                ];
            }
            return $res;
        }
        /**
         * Get Withdraw details
         * ***
         * 
         * states : waiting,done,failed,canceled
         * ***
         * 
         * Returns $res = ["state" => string , "total(in currency)" => string]
        */
        public function getWithdraw(string $withdraw_id) : array{
            $request_path = "/api/v1/withdrawals";
            $full_path = $this->base_url . $request_path;
            $nonce = $this->nonce();
            $headers = [
                "KC-API-TIMESTAMP: " . $nonce ,
                "KC-API-KEY: " . $this->key ,
                "KC-API-PASSPHRASE: " . $this->passphrase() ,
                "KC-API-SIGN: " . $this->signature($request_path,"",$nonce) ,
                "KC-API-KEY-VERSION: 2"
            ];
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                foreach($info->data->items as $value){
                    if($value->id == $withdraw_id){
                        if($value->status == "SUCCESS"){
                            $state = "done";
                        }
                        elseif($value->status == "FAILURE"){
                            $state = "failed";
                        }
                        else{
                            $state = "waiting";
                        }
                        $res = [
                            "class" => $this->class ,
                            "function" => __FUNCTION__ ,
                            "status" => "success" ,
                            "code" => "200" ,
                            "state" => $state ,
                            "total" => $value->amount
                        ];
                        break;
                    }
                }
            }
            return $res;
        }
        /**
         * Submit withdraw
         * ***
         * 
         * Returns $res = ["withdraw_id" => string]
        */
        public function submitWithdraw(string $currency,string $address,string $amount,string $chain = null,string $tag = null) : array{
            $request_path = "/api/v1/withdrawals";
            $full_path = $this->base_url . $request_path;
            $body = [
                "currency" => $currency ,
                "address" => $address ,
                "amount" => $amount ,
            ];
            if($tag != null){
                $body["memo"] = $tag;
            }
            if($chain == "bep20"){
                $body["chain"] = "bsc";
            }
            elseif($chain == "trc20"){
                $body["chain"] = "trx";
            }
            $nonce = $this->nonce();
            $headers = [
                "KC-API-TIMESTAMP: " . $nonce ,
                "KC-API-KEY: " . $this->key ,
                "KC-API-PASSPHRASE: " . $this->passphrase() ,
                "KC-API-SIGN: " . $this->signature($request_path,$body,$nonce,"POST") ,
                "KC-API-KEY-VERSION: 2"
            ];
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                $res = [
                    "class" => $this->class ,
                    "function" => __FUNCTION__ ,
                    "status" => "success" ,
                    "code" => "200" ,
                    "withdraw_id" => $info->withdrawalId
                ];
            }
            return $res;
        }
        /**
         * Internal Transfer of Funds (Wallet to Spot, Spot to Wallet)
         * ***
         * 
         * $to = 'wallet' OR 'spot'
         * Returns $res = ["trans_id" => string]
        */
        public function internalTransfer(string $to,string $currency,string $amount) : array{
            $request_path = "/api/v3/accounts/universal-transfer";
            $full_path = $this->base_url . $request_path;
            if($to == "wallet"){
                $fromAccountType = "TRADE";
                $toAccountType = "MAIN";
            }
            else{
                $fromAccountType = "MAIN";
                $toAccountType = "TRADE";
            }
            $body = [
                "clientOid" => $this->newToken(16) ,
                "currency" => $currency ,
                "amount" => $amount ,
                "fromAccountType" => $fromAccountType ,
                "type" => "INTERNAL" ,
                "toAccountType" => $toAccountType
            ];
            $nonce = $this->nonce();
            $headers = [
                "KC-API-TIMESTAMP: " . $nonce ,
                "KC-API-KEY: " . $this->key ,
                "KC-API-PASSPHRASE: " . $this->passphrase() ,
                "KC-API-SIGN: " . $this->signature($request_path,$body,$nonce,"POST") ,
                "KC-API-KEY-VERSION: 2"
            ];
            $curl = curl_init($full_path);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $info = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $info = json_decode($info);
            $info->httpCode = $httpCode;
            $res = $this->handleResponse($info,__FUNCTION__,$this->class);
            if($res == "ok"){
                $res = [
                    "class" => $this->class ,
                    "function" => __FUNCTION__ ,
                    "status" => "success" ,
                    "code" => "200" ,
                    "trans_id" => $info->orderId
                ];
            }
            return $res;
        }

    }
?>