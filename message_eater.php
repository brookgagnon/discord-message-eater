<?php

require('config.php');

class MessageEater
{

  private $deleteOlderThan;
  private $botToken;
  private $channelIds;
  private $globalRateLimit;

  public function __construct($config)
  {
    $this->deleteOlderThan = $config['delete_older_than'];
    $this->botToken = $config['bot_token'];
    $this->channelIds = $config['channel_ids'];
  }

  public function go()
  {
    foreach($this->channelIds as $channel_id)
    {
      $after = 0;
      while($messages = $this->getMessages($channel_id, $after))
      {
        echo 'got '.count($messages)." messages\n";

        foreach($messages as $message)
        {        
          // if deleteOlderThan seems invalid, or message is newer than specified, break out of foreach+while to move onto next channel.
          if(strtotime($message->timestamp)>=$this->deleteOlderThan || !is_int($this->deleteOlderThan)) break(2);
        
          echo $channel_id.': '.$message->content."\n";
          $after = $message->id;
          $this->deleteMessage($message->channel_id, $message->id);
        }
      }
    }
  }
  
  private function curl($url, $request_type=NULL)
  {
    // see if we're rate limited, and wait the chill out period if necessary.
    if($this->globalRateLimit && time()<$this->globalRateLimit)
    {
      // hmm adding another second to be safe, but i don't think it's needed.
      $wait = 1 + $this->globalRateLimit - time();
      echo 'rate limit, waiting: '.$wait."s\n";
      sleep($wait);
    }
    
    // okay now we can get curl doing it's thing.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER,  ['Authorization: Bot '.$this->botToken]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    if($request_type!==NULL) curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_type);
    
    $response = curl_exec($ch);
    
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    
    $rate_limit_matches = [];
    if(strpos($header, 'X-RateLimit-Remaining: 0') && preg_match('/X-RateLimit-Reset: (\d+)/',$header, $rate_limit_matches))
    {
      if(!empty($rate_limit_matches[1]) && preg_match('/^\d+$/',$rate_limit_matches[1]))
      {
        $this->globalRateLimit = (int) $rate_limit_matches[1];
      }
    }
    
    return json_decode($body);
  }
  
  public function getMessages($channel_id, $after)
  {
    $messages = $this->curl('https://discordapp.com/api/v6/channels/'.$channel_id.'/messages?limit=100&after='.$after);
    if(!isset($messages[0]->id)) return false;
    
    // reverse array to return messages in order, from oldest to newest.
    return array_reverse($messages);
  }
  
  public function deleteMessage($channel_id, $message_id)
  {
    $this->curl('https://discordapp.com/api/v6/channels/'.$channel_id.'/messages/'.$message_id, 'DELETE');
  }

}

$message_eater = new MessageEater($config);
$message_eater->go();
