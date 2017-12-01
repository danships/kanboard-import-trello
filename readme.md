# Kanboard Trello import

This is a simple command-line script to move your [Trello-based](http://www.trello.com) boards to
your self-hosted [kanboard.net](http://www.kanboard.net) instance.

If you have any suggestions or find an issue, please report an [issue](https://github.com/matueranet/kanboard-import-trello/issues), suggestions are welcome!

## Installation
- Perform a `git clone` or download this repository as a zip file to your local PC-
- Execute `composer install` to install the RPC client dependency.

## Usage

    php import.php http://server/jsonrpc.php apitoken trellokey trellotoken trelloboard userid

- server URL, this is the URL to your kanboardservers' jsonrpc.php file
- apiToken, this is the api token for the RPC calls. You can find this value in Settings -> API.
- trelloKey and trelloToken. You can get yourself a key and a token to your board from https://trello.com/app-key (look for "Click here to request a token to be used in the example")
- trelloboard, the shortlink of your Trello Board. You can find that in the URL in your webbrowser, eg. in https://trello.com/b/AbCdEf5g/my-board it would be the AbCdEf5g
- userId, this value is optional. Comments in kanboard require a user that writes the comment. If a valid userId is provided then comments are also copied.

Please note that after the import there will be no users linked to the board. You can use the kanboard admin account to access the Permissions page of
the new project to add users to it.

## Known limitations
- Creation and modification timestamps are not copied
- Attachments are not imported, but are attempted to be downloaded and stored in the folder you are executing the command from.

# Credits
The JSON RPC API interface and the JSON client itself from [@fguillot](https://github.com/fguillot) make creating this script relatively easy.

Also thanks to Trello for making an awesome product, unfortunately it is only available in a hosted version.

Many thanks to [@tpokorra](https://github.com/tpokorra) for providing a pull request to use the Trello API.
