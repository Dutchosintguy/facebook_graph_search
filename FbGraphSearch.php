<?php
require_once __DIR__ . '/vendor/autoload.php';

/**
 * @property string sanitizedKeyword
 */
class FbGraphSearch
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
    // for query all
    private $keyword;
    private $type;
    private $latitude;
    private $longitude;
    // for query specific
    private $id;

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
            } else if ($this->target === 'specific') {
                $this->id = !empty($_GET['id']) ? $_GET['id'] : die('id cannot be empty');
            }
        }

        if (!empty($_GET['latitude']) && !empty($_GET['longitude'])) {
            $this->latitude = $_GET['latitude'];
            $this->longitude = $_GET['longitude'];
        }
    }

    public function doFbQuery()
    {
        if ($this->target === 'all') {
            $this->doAllQuery();
        } else if ($this->target === 'specific') {
            $this->doSpecificQuery();
        }
    }

    private function createFbObj()
    {
        $fb = new Facebook\Facebook([
            'app_id' => $this->fbAppId,
            'app_secret' => $this->fbAppSecret,
            'default_graph_version' => 'v2.8',
        ]);
        $fb->setDefaultAccessToken($this->fbAccessToken);
        return $fb;
    }

    private function doAllQuery()
    {
        $fb = $this->createFbObj();
        $typeQuery = "type={$this->type}";

        // https://developers.facebook.com/docs/php/gettingstarted#making-requests
        try {
            // without .width(700).height(700), the linked picture will be small
            // search?q=Spacex&type=user&fields= id,name,picture.width(700).height(700)
            $endpoint = 'search?q=' . urlencode($this->keyword) . "&$typeQuery&fields=id,name,picture.width(700).height(700)";

            // specify center, ignore distance
            // https://piazza.com/class/ix0mbq6bc597b1?cid=645
            if ($this->type === 'place' && $this->latitude !== null && $this->longitude !== null) {
                $endpoint .= "&center={$this->latitude},{$this->longitude}";
            }

            $response = $fb->get($endpoint);
            $fbArray = $response->getDecodedBody();
            // {
            //   "data": (empty)/[
            //     {
            //       "id": id,
            //       "name: name,
            //       "picture": {
            //         "data": {
            //           "url": photo url, ...(other stuff)
            //         }
            //       }
            //     }, (24 more datas)
            //   ],
            //   "paging": {
            //     "next": url fro returning next 25 json data
            //   }
            // }

            $fbData = (!empty($fbArray['data'])) ? $fbArray['data'] : null;

            $result = [];
            $result['data'] = $this->getNodesData($fbData);

            if (!empty($fbArray['paging'])) {
                if (!empty($fbArray['paging']['next'])) {
                    $result['next'] = $fbArray['paging']['next'];
                }
            }

            header('Content-type: application/json');
            echo(json_encode($result));
            // {
            //   "data": {
            //   [
            //     {
            //       "id": id,
            //       "name": name,
            //       "photoUrl": url
            //     }
            //   ],
            //   "next": url fro returning next 25 json data
            // }
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
        $fb = $this->createFbObj();

        // https://developers.facebook.com/docs/php/gettingstarted#making-requests
        try {
            // event do not have details, thus should not come here!
            if ($this->type === 'event') {
                die("Should not query a specific result of an event");
            }

            // Use source instead of picture can make the linked picture bigger
            // 353851465130?fields=id,name,albums.limit(5){name,photos.limit(2){id,name,source}},posts.limit(5){message,created_time}
            $endpoint = "{$this->id}?fields=id,name,albums.limit(5){name,photos.limit(2){id,name,source}},posts.limit(5){message,created_time}";
            $response = $fb->get($endpoint);
            $fbArray = $response->getDecodedBody();
            // failure:
            // { "error": {(some info about the error)} }
            // success:
            //{
            //  "id": "353851465130",
            //  "name": "SpaceX",
            //  "albums": {
            //    "data": [
            //      {
            //        "name": "Timeline Photos",
            //        "photos": {
            //        "data": [
            //            {
            //              "id": "10159206771960131",
            //              "name": "More photos from today's Falcon 9 launch → flickr.com/spacex",
            //              "source": "https://scontent.xx.fbcdn.net/v/t1.0-0/p480x480/18447328_10159206771960131_3233429325920885979_n.jpg?oh=d34fd2cc4686a80271a2074cda12860c&oe=59B0BCC1"
            //            }, ...(one more photo)
            //        ], (paging ...)
            //      }, (album id)
            //      }, ...(5 more albums)
            //  },
            //  "posts": {
            //    "data": [
            //      {
            //        "message"/"story": "More photos from today's Falcon 9 launch → flickr.com/spacex",
            //        "created_time": "2017-05-16T02:48:13+0000",
            //        "id": "353851465130_10159206771960131"
            //      }, ...(4 more posts)
            //  }
            //}

            $albumsData = (!empty($fbArray['albums'])) ? $fbArray['albums']['data'] : null;
            $albums = $this->getAlbums($albumsData);

            $postsData = (!empty($fbArray['posts'])) ? $fbArray['posts']['data'] : null;
            $posts = $this->getPosts($postsData);
            $result = ['name' => $fbArray['name'], 'albums' => $albums, 'posts' => $posts];

            header('Content-type: application/json');
            echo(json_encode($result));
            // {
            //   "name": name of the node (user/page/...)
            //   "albums" : null/[
            //     {
            //       "name": albumName,
            //       "photos": [
            //         {
            //           "url": url_to_high_resolution_photo
            //         }, ...(1 more photo)
            //       ]
            //     }, ...(4 more albums)
            //   ],
            //   "posts": null/[
            //     {
            //       "content": message/story,
            //       "time": time
            //     }, ...(4 more posts)
            //   ]
            // }
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

    private function getNodesData($fbData)
    {
        $nodesData = null;
        if ($fbData !== null) {
            $nodesData = [];
            foreach ($fbData as $data) {
                $id = $data['id'];
                $name = $data['name'];
                $photoUrl = $data['picture']['data']['url'];
                $nodesData[] = ['id' => $id, 'name' => $name, 'photoUrl' => $photoUrl];
            }
        }
        return $nodesData;
        // [
        //   {
        //     "id": id,
        //     "name": name,
        //     "photoUrl": url
        //   }
        // ]
    }

    private function getAlbums($albumsData)
    {
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
        return $albums;
        // [
        //   {
        //     "name": albumName,
        //     "photos": [
        //       {
        //         "url": url_to_high_resolution_photo
        //       }, ...(1 more photo)
        //     ]
        //   }, ...(4 more albums)
        // ]
    }

    private function getPosts($postsData)
    {
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
        return $posts;
        // [
        //   {
        //     "content": message/story,
        //     "time": time
        //   }, ...(4 more posts)
        // ]
    }
}