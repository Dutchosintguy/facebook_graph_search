<?php
require_once __DIR__ . '/vendor/autoload.php';

/**
 * @property string sanitizedKeyword
 */
class MyApp
{
    function __construct()
    {

        $file = fopen('/home/ec2-user/private_data', 'r') or die('Unable to open private data file!');
        $privateDataJson = fread($file, filesize('/home/ec2-user/private_data'));
        fclose($file);
        $privateData = json_decode($privateDataJson, true);
        $this->fbAccessToken = $privateData['fb_access_token'];
        $this->fbAppId = $privateData['fb_app_id'];
        $this->fbAppSecret = $privateData['fb_app_secret'];
        $this->googleApiKey = $privateData['google_api_key'];
    }

    private $fbAccessToken;
    private $fbAppId;
    private $fbAppSecret;
    private $googleApiKey;
    private $target;

    public function isTargetAll()
    {
        return $this->target === 'all';
    }

    public function isTargetSpecific()
    {
        return $this->target === 'specific';
    }

    private $keyword;

    private $type;

    public function typeEquals($type)
    {
        return $this->type === $type;
    }

    public function echoSelectedIfTypeEquals($type)
    {
        echo $this->typeEquals($type) ? 'selected' : '';
    }

    private $location;

    private $distance;

    private $nodesData;

    private $postMsgs;

    private $albumsRows;

    private $invalidGeoQuery = false;

    public function __get($property)
    {
        if (in_array($property, [
            'keyword',
            'type',
            'location',
            'distance',
            'nodesData',
            'postMsgs',
            'albumsRows',
            'invalidGeoQuery'])) {
            return $this->$property;
        }

        if ($property === 'sanitizedKeyword') {
            return urlencode($this->keyword);
        }
    }

    public function retrieveParameters()
    {
        if (!empty($_GET['target'])) {
            $this->target = in_array($_GET['target'], ['all', 'specific']) ? $_GET['target']
                : die('target should be in ["all", "specific"]');

            $this->keyword = !empty($_GET['keyword']) ? $_GET['keyword'] : die('keyword is empty');
            $this->type = !empty($_GET['type']) ? $_GET['type'] : die('type is empty');
            if (!in_array($this->type, ['user', 'page', 'place', 'event', 'group'])) {
                die("type not in ['user', 'page', 'place', 'event', 'group']");
            }
            if ($this->type === 'place') {
                $this->location = !empty($_GET['location']) ? $_GET['location'] : '';
                $this->distance = !empty($_GET['distance']) ? $_GET['distance'] : '';
            }
        }
    }

    public function doFbQuery()
    {
        if ($this->isTargetAll()) {
            $this->doAllQuery();
        } else if ($this->isTargetSpecific()) {
            $this->doSpecificQuery();
        }
    }

    private function doAllQuery()
    {
        $fb = new Facebook\Facebook([
            'app_id' => $this->fbAppId,
            'app_secret' => $this->fbAppSecret,
            'default_graph_version' => 'v2.8',
        ]);
        $fb->setDefaultAccessToken($this->fbAccessToken);

        $typeQuery = "type={$this->type}";

        if ($this->typeEquals('place')) {
            $geoResult = $this->useGeoApi();

            // do not set location (center, distance) if geo returns no result
            if ($geoResult !== null) {
                list($latitude, $longitude) = $geoResult;
                $typeQuery .= "&center=$latitude,$longitude&distance={$this->distance}";
            } else {
                $this->invalidGeoQuery = true;
            }
        }

        // https://developers.facebook.com/docs/php/gettingstarted#making-requests
        try {
            // without .width(700).height(700), the linked picture will be small
            $endpoint = "search?q={$this->sanitizedKeyword}&$typeQuery&fields=id,name,picture.width(700).height(700)";

            // search place for events
            if ($this->typeEquals('event')) {
                $endpoint .= ',place';
            }


            $response = $fb->get($endpoint);

            $fbArray = $response->getDecodedBody();
            $fbData = (!empty($fbArray['data'])) ? $fbArray['data'] : null;

            if ($fbData === null) {
                $this->nodesData = null;
            } else {
                $this->nodesData = [];
                foreach ($fbData as $data) {
                    $photoUrl = $data['picture']['data']['url'];

                    // us JavaScript to open a new page with the desired photo
                    $profilePhoto = "<a href='#' onclick='openNewWindowWithGivenImgUrl(\"$photoUrl\");'><img src='$photoUrl' width='40' height='30'></a>";

                    $name = $data['name'];

                    if ($this->typeEquals('event')) {
                        $place = (!empty($data['place']['name'])) ? $data['place']['name'] : '';
                        $this->nodesData[] = ['profilePhoto' => $profilePhoto, 'name' => $name, 'place' => $place];
                    } else {
                        $id = $data['id'];
                        $link = "{$_SERVER['PHP_SELF']}?target=specific&id=$id&type={$this->type}&keyword={$this->keyword}";
                        if ($this->typeEquals('place')) {
                            $link .= "&location={$this->location}&distance={$this->distance}";
                        }
                        $details = "<a href='$link'>Details</a>";

                        $this->nodesData[] = ['profilePhoto' => $profilePhoto, 'name' => $name, 'details' => $details];
                    }
                }
            }
        } catch (Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            die("Graph returned an error: {$e->getMessage()}");
        } catch (Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            die("Facebook SDK returned an error: {$e->getMessage()}");
        }
    }

    private function doSpecificQuery()
    {
        $fb = new Facebook\Facebook([
            'app_id' => $this->fbAppId,
            'app_secret' => $this->fbAppSecret,
            'default_graph_version' => 'v2.8',
        ]);
        $fb->setDefaultAccessToken($this->fbAccessToken);

        // https://developers.facebook.com/docs/php/gettingstarted#making-requests
        try {
            $id = !empty($_GET['id']) ? $_GET['id'] : die('id cannot be empty');

            // events do not have details, thus should not come here!
            if ($this->typeEquals('event')) {
                die("Should not query a specific result of an event");
            }

            // Use source instead of picture can make the linked picture bigger
            $endpoint = "$id?fields=id,name,albums.limit(5){name,photos.limit(2){id,name,source}},posts.limit(5)";
            $response = $fb->get($endpoint);
            $fbArray = $response->getDecodedBody();

            $albumsData = (!empty($fbArray['albums'])) ? $fbArray['albums']['data'] : null;
            if ($albumsData == null) {
                $this->albumsRows = null;
            } else {
                $this->albumsRows = [];
                foreach ($albumsData as $albumData) {
                    if (empty($albumData['photos'])) {
                        $this->albumsRows[] = "<tr><td>{$albumData['name']}</td></tr>";
                    } else {
                        $albumName = $albumData['name'];
                        $albumId = str_replace(' ', '-', $albumName);
                        $elem = "<tr><td><a class='album-name-links' id='$albumId' href='#'>$albumName</a></td></tr>";
                        $elem .= "<tr id='{$albumId}-photos' style='display: none;'>";
                        $elem .= '<td>';
                        foreach ($albumData['photos']['data'] as $photo) {
                            $imgLink = "https://graph.facebook.com/v2.8/{$photo['id']}/picture?access_token=" . $this->fbAccessToken;
                            // use JavaScript to open a new page with the picture
                            $elem .= "<a href='#' onclick='openNewWindowWithGivenImgUrl(\"$imgLink\")'><img src='$imgLink' width='80px' height='80px'></a>";
                        }
                        $elem .= '</td>';
                        $elem .= '</tr>';
                        $this->albumsRows[] = $elem;
                    }
                }
            }

            $postsData = (!empty($fbArray['posts'])) ? $fbArray['posts']['data'] : null;
            if ($postsData == null) {
                $this->postMsgs = null;
            } else {
                $postMsgs = [];
                foreach ($postsData as $postData) {
                    if (!empty($postData['message'])) {
                        $this->postMsgs[] = $postData['message'];
                    }
                }
                if (!empty($postMsgs)) {
                    $this->postMsgs = $postMsgs;
                }
            }
        } catch (Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            die("Graph returned an error: {$e->getMessage()}");
        } catch (Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            die("Facebook SDK returned an error: {$e->getMessage()}");
        }
    }

    private function useGeoApi()
    {
        if (empty($this->location)) {
            return null;
        }

        // use Google Geocoding API
        $address = urlencode($this->location);
        $geoUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=$address&key=" . $this->googleApiKey;

        $geoJson = file_get_contents($geoUrl);

        $geoArray = json_decode($geoJson, true);

        if ($geoArray['status'] !== 'OK') {
            return null;
        }

        $latitude = $geoArray['results']['0']['geometry']['location']['lat'];
        $longitude = $geoArray['results']['0']['geometry']['location']['lng'];
        return [$latitude, $longitude];
    }
}

$myApp = new MyApp();
$myApp->retrieveParameters();
$myApp->doFbQuery();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Search</title>
    <style>
        /*do not change color of visited link*/
        a:visited {
            color: #0000EE;
        }

        #query-div {
            width: 50%;
            background-color: #f0f0f0;
            border: 3px solid lightgray;
            margin: auto;
        }

        #no-record {
            width: 60%;
            margin: auto;
            border: 2px solid lightgray;
            background-color: #f0f0f0;
            text-align: center;
        }

        #query-table {
            width: 100%;
        }

        #query-table tr {
            height: 25px;
        }

        #query-table tr td:nth-child(1) {
            width: 60px;
        }

        #query-table tr td:nth-child(2) {
            width: 150px;
        }

        #query-table tr td:nth-child(3) {
            width: 60px;
        }

        #query-results {
            margin-top: 50px;
        }

        #all-results-table {
            text-align: left;
            border-collapse: collapse;
            width: 60%;
            margin: auto;
            border: 3px solid lightgray;
        }

        #all-results-table th, #all-results-table td {
            border: 1px solid lightgray;
        }

        #all-results-table th {
            background-color: #f0f0f0;
        }

        .albums-posts {
            width: 60%;
            text-align: center;
            margin: 20px auto 30px;
        }

        .albums-posts-not-found {
            border: 1px solid darkgray;
            background-color: #fbfbfb;
        }

        .albums-posts-found {
            background-color: darkgray;
        }

        .albums-posts-table {
            font-family: sans-serif;
            display: none;
            width: 60%;
            margin: auto;
            border-collapse: collapse;
            border: 1px solid darkgray;
            text-align: left;
        }

        .albums-posts-table tr {
            border: 1px solid darkgray;
        }

        .albums-posts-table td {
            /*for width to expand to maximum width of table*/
            /*width 100% does not work*/
            width: 1000px;
        }
    </style>
</head>
<body>
<div id="query-div">
    <h2 style="text-align: center; font-style: italic; margin: 0;">Facebook Search</h2>
    <hr style="margin: 0 10px;">
    <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="get">
        <!--  use target value ['all', 'specific'] to separate show all search result or Albums, Posts of a specific one-->
        <input type="hidden" name="target" value="all">
        <table id="query-table">
            <tr>
                <td><label for="keyword">Keyword</label></td>
                <td>
                    <input id="keyword" name="keyword" type="text" required value="<?php echo $myApp->keyword; ?>">
                </td>
            </tr>
            <tr>
                <td><label for="type">Type:</label></td>
                <td>
                    <select id="type" name="type">
                        <option id="user" value="user" <?php $myApp->echoSelectedIfTypeEquals('user') ?>>Users
                        </option>
                        <option id="page" value="page" <?php $myApp->echoSelectedIfTypeEquals('page') ?>>Pages
                        </option>
                        <option id="event" value="event" <?php $myApp->echoSelectedIfTypeEquals('event') ?>>Events
                        </option>
                        <option id="place" value="place" <?php $myApp->echoSelectedIfTypeEquals('place') ?>>Places
                        </option>
                        <option id="group" value="group" <?php $myApp->echoSelectedIfTypeEquals('group') ?>>Groups
                        </option>
                    </select>
                </td>
            </tr>
            <tr>
                <td id="loc-label">
                    <?php if ($myApp->typeEquals('place')): ?>
                        <label for="location">Location</label>
                    <?php endif; ?>
                </td>
                <td id="loc-input">
                    <?php if ($myApp->typeEquals('place')): ?>
                        <input id="location" name="location" value="<?php echo $myApp->location ?>">
                    <?php endif; ?>
                </td>
                <td id="dst-label">
                    <?php if ($myApp->typeEquals('place')): ?>
                        <label for="distance">Distance(meter)</label>
                    <?php endif; ?>
                </td>
                <td id="dst-input">
                    <?php if ($myApp->typeEquals('place')): ?>
                        <input id="distance" name="distance" value="<?php echo $myApp->distance ?>">
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td></td>
                <td>
                    <input type="submit" value="Search">
                    <input id="clear" type="button" value="clear">
                </td>
            </tr>
        </table>
    </form>
</div>

<div id="query-results">
    <?php if ($myApp->isTargetAll()): ?>
        <?php
        // has distance, no location
        if ($myApp->location === '' && $myApp->distance !== '') {
            die("Distance specified without location or address");
        }

        // no result from google geo api
        if ($myApp->invalidGeoQuery) {
            echo 'Address is invalid <br>';
            echo 'Display query results without specifying latitude and longitude <br>';
        }
        ?>
        <?php if ($myApp->nodesData == null): ?>
            <div id="no-record">
                No record has been found
            </div>
        <?php else: ?>
            <table id="all-results-table">
                <tr>
                    <th>Profile Photo</th>
                    <th>Name</th>
                    <th><?php echo (!$myApp->typeEquals('event')) ? 'Details' : 'Place'; ?></th>
                </tr>
                <?php foreach ($myApp->nodesData as $nodeData): ?>
                    <tr>
                        <td><?php echo $nodeData['profilePhoto'] ?></td>
                        <td><?php echo $nodeData['name'] ?></td>
                        <td><?php echo (!$myApp->typeEquals('event')) ? $nodeData['details'] : $nodeData['place']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($myApp->isTargetSpecific()): ?>
        <?php if ($myApp->albumsRows == null): ?>
            <div class="albums-posts albums-posts-not-found">No Albums has been found</div>
        <?php else: ?>
            <div class="albums-posts albums-posts-found"><a id="albums-link" href="#">Albums</a></div>
            <table id="albums" class="albums-posts-table">
                <?php
                foreach ($myApp->albumsRows as $albumRows) {
                    echo $albumRows;
                }
                ?>
            </table>
        <?php endif; ?>

        <?php if ($myApp->postMsgs == null): ?>
            <div class="albums-posts albums-posts-not-found">No Posts has been found</div>
        <?php else: ?>
            <div class="albums-posts albums-posts-found"><a id="posts-link" href="#">Posts</a></div>
            <table id="posts" class="albums-posts-table">
                <tr>
                    <th>Message</th>
                </tr>
                <?php foreach ($myApp->postMsgs as $postMsg): ?>
                    <tr>
                        <td><?php echo $postMsg ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endif; ?>

</div>

<script>
    window.onload = function () {
        var type = document.getElementById('type');
        type.addEventListener('change', selectionChanged);

        var clearInput = document.getElementById('clear');
        clearInput.addEventListener('click', clear);

        var albumsLink = document.getElementById('albums-link');
        if (albumsLink != null) {
            albumsLink.addEventListener('click', toggleAlbums);
        }

        var postsLink = document.getElementById('posts-link');
        if (postsLink != null) {
            postsLink.addEventListener('click', togglePosts);
        }

        var albumNameLinks = document.getElementsByClassName('album-name-links');
        if (albumNameLinks != null) {
            for (var i = 0; i < albumNameLinks.length; ++i) {
                var albumNameLink = albumNameLinks[i];
                albumNameLink.addEventListener('click', function () {
                    var albumPhotos = document.getElementById(this.id + '-photos');
                    albumPhotos.style.display = (albumPhotos.style.display === 'none')
                        ? 'inherit' : 'none';
                });
            }
        }
    };

    function selectionChanged() {
        var type = document.getElementById('type');
        var selectedValue = type.options[type.selectedIndex].value;
        selectedValue == 'place' ? showLocationAndDistance() : hideLocationAndDistance();
    }

    function showLocationAndDistance() {
        document.getElementById('loc-label').innerHTML = '<label for="location">Location</label>';
        document.getElementById('loc-input').innerHTML = '<input id="location" name="location">';
        document.getElementById('dst-label').innerHTML = '<label for="distance">Distance(meter)</label>';
        document.getElementById('dst-input').innerHTML = '<input id="distance" name="distance">';
    }

    function hideLocationAndDistance() {
        document.getElementById('loc-label').innerHTML = '';
        document.getElementById('loc-input').innerHTML = '';
        document.getElementById('dst-label').innerHTML = '';
        document.getElementById('dst-input').innerHTML = '';
    }

    function clear() {
        clearInputs();
        clearQueryResults();

        function clearInputs() {
            clearInput('keyword');
            resetOption();
            clearInput('location');
            clearInput('distance');

            function clearInput(id) {
                var input = document.getElementById(id);
                if (input != null) {
                    input.value = '';
                }
            }

            function resetOption() {
                // selection changed event is not triggered, needs to manually hide location and distance
                hideLocationAndDistance();

                var userOpt = document.getElementById('user');
                userOpt.selected = true;

                var pageOpt = document.getElementById('page');
                pageOpt.selected = false;

                var eventOpt = document.getElementById('event');
                eventOpt.selected = false;

                var placeOpt = document.getElementById('place');
                placeOpt.selected = false;

                var groupOpt = document.getElementById('group');
                groupOpt.selected = false;
            }
        }

        function clearQueryResults() {
            var queryResultDiv = document.getElementById('query-results');
            queryResultDiv.innerHTML = '';
        }
    }

    function toggleAlbums() {
        var albums = document.getElementById('albums');

        if (albums.style.display === 'inherit') {
            albums.style.display = 'none';
        } else {
            albums.style.display = 'inherit';

            var posts = document.getElementById('posts');
            if (posts != null) {
                posts.style.display = 'none';
            }
        }
    }

    function togglePosts() {
        var posts = document.getElementById('posts');

        if (posts.style.display === 'inherit') {
            posts.style.display = 'none';
        } else {
            posts.style.display = 'inherit';

            var albums = document.getElementById('albums');
            if (albums != null) {
                albums.style.display = 'none';
            }
        }
    }

    function openNewWindowWithGivenImgUrl(imgUrl) {
        var w = window.open();
        w.document.open();
        w.document.write('<img src="' + imgUrl + '">');
        w.document.close();
    }
</script>
<noscript></noscript>
</body>
</html>

