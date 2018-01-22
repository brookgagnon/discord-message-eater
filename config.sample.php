<?php

$config = [

  // unix timestamp to delete messages up to, see http://php.net/manual/en/function.strtotime.php
  'delete_older_than' => strtotime('-3 months'),
  
  // bot token provided by discord
  'bot_token' => 'token-goes-here',
  
  // channel IDs to delete messages from
  'channel_ids' => [
    000000000000000000,
    000000000000000001,
    000000000000000002
  ],
  
  // callback in case you want to do something with messages as they are deleted (like archive to database)
  'delete_callback' => function($message) { /* do something with message object here */ }
  
];
