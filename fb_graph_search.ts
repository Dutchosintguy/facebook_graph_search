declare var FB;

// https://developers.facebook.com/docs/javascript/quickstart
window.fbAsyncInit = function () {
    FB.init({
        appId: '177970666050443',
        xfbml: true,
        version: 'v2.8'
    });
    FB.AppEvents.logPageView();
};

(function (d, s, id) {
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) {
        return;
    }
    js = d.createElement(s);
    js.id = id;
    js.src = "//connect.facebook.net/en_US/sdk.js";
    fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'facebook-jssdk'));

interface NodeData {
    id: string,
    name: string,
    photoUrl: string,
    type?: string
}

interface AllResponse {
    data: NodeData[]
}

interface AlbumData {
    name: string,
    photos: {
        url: string
    }[]
}

interface PostData {
    content: string,
    time: string
}

interface SpecificResponse {
    name: string,
    albums: AlbumData[],
    posts: PostData[]
}

interface AngularScope {
    coords: Coordinates,
    activeNode: NodeData,
    nodes: {
        users: AllResponse,
        pages: AllResponse,
        events: AllResponse,
        places: AllResponse,
        groups: AllResponse
    },
    keyword: string,
    visibleItem: {
        select: (arg: string) => void,
        queryAll: {
            select: (arg: string) => void,
            isVisible: boolean,
            showProgressBar: boolean,
            showNodes: boolean,
            showFavorites: boolean
        },
        querySpecific: {
            select: (arg: string) => void,
            isVisible: boolean,
            showProgressBar: boolean,
            showResults: boolean
        }
    },
    favorites: NodeData[],
    activeType: string,
    detail: SpecificResponse,

    clearClicked: () => void,
    searchClicked: () => void,
    detailsClicked: (node: NodeData) => void,
    backClicked: () => void,
    typeClicked: (type: string) => void,
    nextClicked: () => void,
    previousClicked: () => void,
    albumClicked: (index: number) => void

    isFavorite: (node: NodeData) => boolean,
    toggleFavorite: (node: NodeData) => void,
    setFavorite: (node: NodeData) => void,
    unsetFavorite: (node: NodeData) => void,
    postToFacebook: (node: NodeData) => void,
    shouldShowAlbum: (index: number) => void
}

angular.module('myApp', ['ngAnimate']).controller('myCtrl', function ($scope: AngularScope, $http) {
    $scope.coords = null;
    // follow the video, get coord at the beginning
    navigator.geolocation.getCurrentPosition(
        function success(pos: {coords: Coordinates}) {
            $scope.coords = pos.coords;
        },
        function error(err) {
            alert('Unable to get your location, will proceed without it. Error: ' + err);
        }
    );

    $scope.activeNode = null;
    $scope.keyword = '';
    $scope.visibleItem = {
        select: function (arg: string) {
            this.queryAll.isVisible = false;
            this.querySpecific.isVisible = false;

            this[arg].isVisible = true;
        },

        queryAll: {
            select: function (arg: string) {
                this.showProgressBar = false;
                this.showNodes = false;
                this.showFavorites = false;

                this[arg] = true
            },
            isVisible: true,
            showProgressBar: false,
            showNodes: true,
            showFavorites: false
        },
        querySpecific: {
            select: function (arg) {
                this.showProgressBar = false;
                this.showResults = false;

                this[arg] = true;
            },
            isVisible: false,
            showProgressBar: false,
            showResults: true
        }
    };

    $scope.clearClicked = function () {
        $scope.keyword = '';
        $scope.nodes = null;
        $scope.visibleItem.select('queryAll');
        if ($scope.activeType === 'favorites') {
            $scope.visibleItem.queryAll.select('showFavorites');
        } else {
            $scope.visibleItem.queryAll.select('showNodes');
        }

        showFirstAlbum();
    };

    $scope.searchClicked = function () {
        if ($scope.keyword.trim() === '') {
            alert('Search keyword should not be empty');
            return;
        }


        let url = `http://sample-env.f7yg2xz9yp.us-west-1.elasticbeanstalk.com/fb_graph_search_json.php?target=all&keyword=${$scope.keyword}`;

        if ($scope.coords !== null) {
            url += `&latitude=${$scope.coords.latitude}&longitude=${$scope.coords.longitude}`
        }

        function errorCallback(response: string) {
            alert('error' + response);
        }

        $http.get(url + '&type=user').then(
            function (response: {data: AllResponse}) {
                $scope.nodes = {};
                $scope.nodes.users = response.data;
                $http.get(url + '&type=page').then(
                    function (response: {data: AllResponse}) {
                        $scope.nodes.pages = response.data;
                        $http.get(url + '&type=event').then(
                            function (response: {data: AllResponse}) {
                                $scope.nodes.events = response.data;
                                $http.get(url + '&type=place').then(
                                    function (response: {data: AllResponse}) {
                                        $scope.nodes.places = response.data;
                                        $http.get(url + '&type=group').then(
                                            function (response: {data: AllResponse}) {
                                                $scope.nodes.groups = response.data;
                                                $scope.visibleItem.select('queryAll');
                                                if ($scope.activeType === 'favorites') {
                                                    $scope.visibleItem.queryAll.select('showFavorites');
                                                } else {
                                                    $scope.visibleItem.queryAll.select('showNodes');
                                                }
                                            },
                                            errorCallback
                                        );
                                    },
                                    errorCallback
                                );
                            },
                            errorCallback
                        );
                    },
                    errorCallback
                );
            },
            errorCallback
        );

        $scope.visibleItem.select('queryAll');
        if ($scope.activeType === 'favorites') {
            $scope.visibleItem.queryAll.select('showFavorites');
        } else {
            $scope.visibleItem.queryAll.select('showProgressBar');
        }

        showFirstAlbum();
    };

    // keep track of favorites
    $scope.favorites = (localStorage.getItem('favorites') !== null) ? JSON.parse(localStorage.getItem('favorites')) : {};

    $scope.isFavorite = function (node: NodeData) {
        for (const favoriteId in $scope.favorites) {
            if (node.id == favoriteId) { // use == because node.id is int while favoriteId is string
                return true;
            }
        }
        return false;
    };
    $scope.toggleFavorite = function (node: NodeData) {
        if ($scope.isFavorite(node)) {
            $scope.unsetFavorite(node);
        } else {
            $scope.setFavorite(node);
        }
    };
    $scope.setFavorite = function (node: NodeData) {
        $scope.favorites[Number(node.id)] = node;
        $scope.favorites[Number(node.id)].type = $scope.activeType;

        // save to localStorage
        // https://www.w3schools.com/html/html5_webstorage.asp
        // https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON/stringify
        // Example of using JSON.stringify() with localStorage
        localStorage.setItem('favorites', JSON.stringify($scope.favorites));
    };
    $scope.unsetFavorite = function (node: NodeData) {
        delete $scope.favorites[node.id];
        localStorage.setItem('favorites', JSON.stringify($scope.favorites));
    };


    $scope.detailsClicked = function (node: NodeData) {
        $scope.activeNode = node;
        $scope.visibleItem.select('querySpecific');
        $scope.visibleItem.querySpecific.select('showProgressBar');

        $http({
            method: 'Get',
            url: 'http://sample-env.f7yg2xz9yp.us-west-1.elasticbeanstalk.com/fb_graph_search_json.php?target=specific&id=' + node.id
        }).then(function successCallback(response: {data: SpecificResponse}) {
                $scope.visibleItem.select('querySpecific');
                $scope.visibleItem.querySpecific.select('showResults');
                $scope.detail = response.data;
            }, function errorCallback() {
                alert('error');
            }
        );
    };

    $scope.backClicked = function () {
        showFirstAlbum();

        $scope.visibleItem.select('queryAll');
        if ($scope.activeType === 'favorites') {
            $scope.visibleItem.queryAll.select('showFavorites');
        } else {
            $scope.visibleItem.queryAll.select('showNodes');
        }
    };

    $scope.activeType = 'users';
    $scope.typeClicked = function (type: string) {
        $scope.activeType = type;

        if (type === 'favorites') {
            $scope.visibleItem.select('queryAll');
            $scope.visibleItem.queryAll.select('showFavorites');
        } else {
            $scope.visibleItem.select('queryAll');
            $scope.visibleItem.queryAll.select('showNodes');
        }

        showFirstAlbum();
    };

    $scope.nextClicked = function () {
        function errorCallback(response: string) {
            alert('error' + response);
        }

        $http.get($scope.nodes[$scope.activeType].next).then(function (response) {
            updateNodesData(response);
            $scope.visibleItem.queryAll.select('showNodes');
        }, errorCallback);
        $scope.visibleItem.queryAll.select('showProgressBar');
    };

    $scope.previousClicked = function () {
        function errorCallback(response: string) {
            alert('error' + response);
        }

        $http.get($scope.nodes[$scope.activeType].previous).then(function (response) {
            updateNodesData(response);
            $scope.visibleItem.queryAll.select('showNodes');
        }, errorCallback);
        $scope.visibleItem.queryAll.select('showProgressBar');
    };

    function updateNodesData(response) {
        if (response.data.data.length === 0) {
            // maybe bug of fb api, do nothing
            alert('bug of fb, no data in next page');
            return;
        }

        $scope.nodes[$scope.activeType] = response.data;
        for (let i = 0; i < $scope.nodes[$scope.activeType].data.length; ++i) {
            $scope.nodes[$scope.activeType].data[i].photoUrl = $scope.nodes[$scope.activeType].data[i].picture.data.url;
        }

        if ($scope.nodes[$scope.activeType]['paging'] !== undefined) {
            if ($scope.nodes[$scope.activeType]['paging']['previous'] !== undefined) {
                $scope.nodes[$scope.activeType]['previous'] = $scope.nodes[$scope.activeType]['paging']['previous'];
            }

            if ($scope.nodes[$scope.activeType]['paging']['next'] !== undefined) {
                $scope.nodes[$scope.activeType]['next'] = $scope.nodes[$scope.activeType]['paging']['next'];
            }
        }
    }

    $scope.postToFacebook = function (node: NodeData) {
        // https://developers.facebook.com/docs/javascript/examples
        // Trigger a Share dialog

        FB.ui({
            method: 'feed',
            link: window.location.href,
            // the below one should be correct, but since the result is a redirect, it cannot be used
            // link: 'https://www.facebook.com/' + node.id,
            picture: node.photoUrl,
            name: node.name,
            caption: 'FB SEARCH FROM USC CSCI571'
        }, function (response: any) {
            if (response && !response.error_message) {
                alert('Posted Successfully');
            }
            else {
                alert('Not Posted');
            }
        });
    };

    let showAlbum = [true, false, false, false, false];

    function showFirstAlbum() {
        showAlbum = [true, false, false, false, false];
    }

    $scope.albumClicked = function (index: number) {
        if (showAlbum[index]) {
            // fold opened album
            showAlbum[index] = false;
            return;
        }

        for (let i = 0; i < 5; ++i) {
            showAlbum[i] = false;
        }

        showAlbum[index] = true;
    };
    $scope.shouldShowAlbum = function (index: number) {
        return showAlbum[index];
    }
}).filter('notEmpty', function () { // http://stackoverflow.com/a/23396452
    return function (obj: object) {
        for (const property in obj) {
            if (obj.hasOwnProperty(property)) {
                return true;
            }
        }
        return false;
    }
}).directive('mySlide', [
    // http://ng-learn.org/2014/01/Dom-Manipulations/
    function () {
        return {
            restriction: 'A',
            link: function (scope, element, attrs) {
//                         https://docs.angularjs.org/api/ng/type/$rootScope.Scope
//                         $watch
                scope.$watch(attrs.mySlide, function (newValue) {
//                     newValue: new value of the expression of data-my-slide
//                     data-my-slide="THIS EXPRESSION"
                    if (newValue) {
                        return jQuery(element).slideDown();
                        // return element.slideDown();
                    } else {
                        return jQuery(element).slideUp();
                        // return element.slideUp();
                    }
                })
            }
        }
    }
]);
