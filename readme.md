#Kanboard Trello import

This is a simple command-line script to move your [Trello-based](http://www.trello.com) boards to
your self-hosted [kanboard.net](http://www.kanboard.net) instance.

If you have any suggestions or found an issue, please report an [issue](https://github.com/matueranet/kanboard-import-trello/issues), suggestions are welcome!

##Installation
Perform a `git clone` or download this repository as a zip file to your local PC. Execute `composer install` to install the RPC client dependency.

##Usage
`php import.php http://server/jsonrpc.php apitoken jsonfile%s [userId]`

- server URL, this is the URL to your kanboardservers' jsonrpc.php file
- apiToken, this is the api token for the RPC calls. You can find this value in Settings -> API.
- jsonfile, the json that you got from Trello by doing: Menu -> Share, Print and Export... -> Export JSON
- userId, this value is optional. Comments in kanboard require a user that writes the comment. If a valid userId is provided then comments are also copied.

##Known limitations
- Creation and modification timestamps are not copied
- Attachments are not imported, but are attempted to be downloaded and stored in the folder you are executing the command from.
- Not all comments can be copied. This is a result of the structure of the Trello export. Comments are not exported as part of a card. They are extracted from the list of actions, which is limited to 1000.

#Credits
The JSON RPC API interface and the JSON client itself from [@fguillot](https://github.com/fguillot) make creating this script relatively easy.

Also thanks to Trello for making an awesome product, unfortunately it is only available in a hosted version.