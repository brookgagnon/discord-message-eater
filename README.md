# discord-message-eater
* PHP Discord bot script to delete messages older than a certain age.

## How To Use
1. Copy config.sample.php to config.php.
2. Set config items.
3. Test on test server.
4. Run with cron, etc. as needed.

## Things To Improve
- add error and unexpected response handling
- discord rate limits are per-channel for delete. tracking these separately would allow deleting messages faster.
- what else could go horribly wrong?

## License & Copyright
* MIT License
* Copyright (c) 2018 Brook Gagnon
* See LICENSE file.