<?php

require('config.php');

class MessageEater
{

  private $deleteOlderThan;
  private $botToken;
  private $channelIds;
  private $channelNames;
  private $rateLimits;
  private $maxChannelNameLength;
  private $deleteCallback;

  public function __construct($config)
  {
    $this->deleteOlderThan = $config['delete_older_than'];
    $this->botToken = $config['bot_token'];
    $this->channelIds = $config['channel_ids'];

    if(isset($config['delete_callback']) && is_callable($config['delete_callback'])) $this->deleteCallback = $config['delete_callback'];
    else $this->deleteCallback = FALSE;
    
    // get our channel names. if we can't get a name, we probably can't do anything else, so remove the channel from the list.
    $this->channelNames = [];
    $this->maxChannelNameLength = 0;
    foreach($this->channelIds as $channel_id)
    {
      $channel = $this->getChannel($channel_id);
      if(isset($channel->name))
      {
        $this->channelNames[$channel_id] = $channel->name;
        $this->maxChannelNameLength = max($this->maxChannelNameLength,strlen($channel->name));
      }
      else
      {
        $this->debug(null, 'could not get channel name for ID '.$channel_id.', removing from list.');
        $this->removeChannelId($channel_id);
      }
    }

    // keep track of rate limit per channel. this isn't quite how discord works, but it's good enough for our purposes.
    $this->rateLimits = [];
    foreach($this->channelIds as $channel_id) $this->rateLimits[$channel_id] = 0;
  }

  public function go()
  {
    // keep track of last message deleted for each channel
    $after = [];
    foreach($this->channelIds as $channel_id) $after[$channel_id] = 0;

    // keep looping through channels as long as we have the IDs in our array.
    // channel IDs are removed from the array once delete for that channel is finished.
    while(count($this->channelIds))
    {
      foreach($this->channelIds as $channel_id)
      {
        while($messages = $this->getMessages($channel_id, $after[$channel_id]))
        {
          $this->debug($channel_id, 'got '.count($messages).' message(s)');

          foreach($messages as $message)
          {
            // if deleteOlderThan seems invalid, or message is newer than specified,
            // stop deleting messages and remove this channel from the list.
            if(strtotime($message->timestamp)>=$this->deleteOlderThan || !is_int($this->deleteOlderThan))
            {
              $this->debug($channel_id, 'done deleting old messages');
              $this->removeChannelId($channel_id);
              break(2);
            }

            $this->log($channel_id, substr($message->timestamp,0,10).' | '.$message->author->username.' | '.$message->content);
            $after[$channel_id] = $message->id;
            $this->deleteMessage($message->channel_id, $message->id);
            if($this->deleteCallback) call_user_func($this->deleteCallback, $message);

            // if we have a rate limit, skip to next channel but keep ID in array so we come back to it.
            if(time()<$this->rateLimits[$channel_id])
            {
              $this->debug($channel_id, 'rate limit hit, moving to next channel');
              break(2);
            }
          }
        }
        
        // if no messages left, remove channel ID
        if(!$messages)
        {
          $this->debug($channel_id, 'done deleting old messages');
          $this->removeChannelId($channel_id);
        }
      }
    }
  }
  
  private function removeChannelId($channel_id)
  {
    $key = array_search($channel_id, $this->channelIds);
    if($key!==FALSE) unset($this->channelIds[$key]);
  }

  private function debug($channel_id, $message)
  {
    $channel_name = $channel_id ? str_pad($this->channelNames[$channel_id], $this->maxChannelNameLength, ' ', STR_PAD_LEFT).' | ' : '';
    fwrite(STDERR, $channel_name.$message."\n");
  }

  private function log($channel_id, $message)
  {
    $channel_name = $channel_id ? str_pad($this->channelNames[$channel_id], $this->maxChannelNameLength, ' ', STR_PAD_LEFT).' | ' : '';
    fwrite(STDOUT, $channel_name.$message."\n");
  }

  private function curl($url, $channel_id, $request_type=NULL)
  {
    // see if we're rate limited, and wait the chill out period if necessary.
    if(time()<$this->rateLimits[$channel_id])
    {
      // hmm adding another second to be safe, but i don't think it's needed.
      $wait = 1 + $this->rateLimits[$channel_id] - time();
      $this->debug($channel_id, 'rate limit, waiting '.$wait.'s');
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
    if(strpos($header, 'X-RateLimit-Remaining: 0')!==FALSE && preg_match('/X-RateLimit-Reset: (\d+)/',$header, $rate_limit_matches))
    {
      $this->rateLimits[$channel_id] = (int) $rate_limit_matches[1];
    }
    
    return json_decode($body);
  }

  private function getChannel($channel_id)
  {
    $channel = $this->curl('https://discordapp.com/api/v6/channels/'.$channel_id, $channel_id);
    return $channel;
  }

  private function getMessages($channel_id, $after)
  {
    $messages = $this->curl('https://discordapp.com/api/v6/channels/'.$channel_id.'/messages?limit=100&after='.$after, $channel_id);
    if(!isset($messages[0]->id)) return false;
    
    // reverse array to return messages in order, from oldest to newest.
    return array_reverse($messages);
  }

  private function deleteMessage($channel_id, $message_id)
  {
    $this->curl('https://discordapp.com/api/v6/channels/'.$channel_id.'/messages/'.$message_id, $channel_id, 'DELETE');
  }

}

$message_eater = new MessageEater($config);
$message_eater->go();
