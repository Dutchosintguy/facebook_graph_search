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

    private $latitude;

    private $longitude;

    public function __get($property)
    {
        if ($property === 'sanitizedKeyword') {
            return urlencode($this->keyword);
        }
    }

    public function retrieveParameters()
    {
        if (!empty($_GET['target'])) {
            $this->target = in_array($_GET['target'], ['all', 'specific']) ? $_GET['target']
                : die('target should be in ["all", "specific"]');

            if ($this->target === 'all') {
                $this->keyword = !empty($_GET['keyword']) ? $_GET['keyword'] : die('keyword is empty');
                $this->type = !empty($_GET['type']) ? $_GET['type'] : die('type is empty');
                if (!in_array($this->type, ['user', 'page', 'place', 'event', 'group'])) {
                    die("type not in ['user', 'page', 'place', 'event', 'group']");
                }
            }
        }

        if (!empty($_GET['latitude']) && !empty($_GET['longitude'])) {
            $this->latitude = $_GET['latitude'];
            $this->longitude = $_GET['longitude'];
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

        // https://developers.facebook.com/docs/php/gettingstarted#making-requests
        try {
            // without .width(700).height(700), the linked picture will be small
            $endpoint = "search?q={$this->sanitizedKeyword}&$typeQuery&fields=id,name,picture.width(700).height(700)";

            // specify center, ignore distance
            // https://piazza.com/class/ix0mbq6bc597b1?cid=645
            if ($this->type === 'place' && $this->latitude !== null && $this->longitude !== null) {
                $endpoint .= "&center={$this->latitude},{$this->longitude}";
            }

            $response = $fb->get($endpoint);

            $fbArray = $response->getDecodedBody();
            $fbData = (!empty($fbArray['data'])) ? $fbArray['data'] : null;

            $result = [];
            $nodesData = null;
            // TODO let ajax client know there's no data
            if ($fbData !== null) {
                $nodesData = [];
                foreach ($fbData as $data) {
                    $id = $data['id'];
                    $name = $data['name'];
                    $photoUrl = $data['picture']['data']['url'];
                    $nodesData[] = ['id' => $id, 'name' => $name, 'photoUrl' => $photoUrl];
                }
            }
            $result['data'] = $nodesData;

            if (!empty($fbArray['paging'])) {
                if (!empty($fbArray['paging']['next'])) {
                    $result['next'] = $fbArray['paging']['next'];
                }
            }

            echo(json_encode($result));

        } catch (Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            // TODO let client know
            die("Graph returned an error: {$e->getMessage()} ");
        } catch (Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            // TODO let client know
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

            // event do not have details, thus should not come here!
            if ($this->typeEquals('event')) {
                die("Should not query a specific result of an event");
            }

            // Use source instead of picture can make the linked picture bigger
            $endpoint = "$id?fields=id,name,albums.limit(5){name,photos.limit(2){id,name,source}},posts.limit(5){message,created_time}";
            $response = $fb->get($endpoint);
            $fbArray = $response->getDecodedBody();

            $albumsData = (!empty($fbArray['albums'])) ? $fbArray['albums']['data'] : null;
            $albums = null;
            if ($albumsData !== null) {
                $albums = [];
                foreach ($albumsData as $albumData) {
                    $albumName = $albumData['name'];
                    $photos = [];
                    foreach ($albumData['photos']['data'] as $photo) {
                        $photos[] = ['url' => "https://graph.facebook.com/v2.8/{$photo['id']}/picture?access_token=" . $this->fbAccessToken];
                    }

                    $albums[] = ['name' => $albumName, 'photos' => $photos];
                }
            }

            $postsData = (!empty($fbArray['posts'])) ? $fbArray['posts']['data'] : null;
            $posts = null;
            if ($postsData !== null) {
                $posts = [];
                foreach ($postsData as $postData) {
                    $time = $postData['created_time'];
                    // 2017-03-19T16:16:37+0000 -> 2017-03-19 16:16:37+0000
                    $time = str_replace('T', ' ', $time);
                    // 2017-03-19 16:16:37+0000 -> 2017-03-19 16:16:37
                    $time = substr($time, 0, strpos($time, '+'));

                    if (!empty($postData['message'])) {
                        $posts[] = ['content' => $postData['message'], 'time' => $time];
                    } else if (!empty($postData['story'])) {
                        $posts[] = ['content' => $postData['story'], 'time' => $time];
                    }
                }
            }
            $result = ['name' => $fbArray['name'], 'albums' => $albums, 'posts' => $posts];

            echo(json_encode($result));
            die();
        } catch (Facebook\Exceptions\FacebookResponseException $e) {
            // TODO notify client
            // When Graph returns an error
            die("Graph returned an error: {$e->getMessage()}");
        } catch (Facebook\Exceptions\FacebookSDKException $e) {
            // TODO notify client
            // When validation fails or other local issues
            die("Facebook SDK returned an error: {$e->getMessage()}");
        }
    }
}

$myApp = new MyApp();
$myApp->retrieveParameters();
$myApp->doFbQuery();
