interface NodeData {
    id: string,
    name: string,
    picture: {
        data: {
            url: string
        }
    },
    type?: string,
}

interface AllResponse {
    data: NodeData[],
    paging?: {
        previous?: string,
        next?: string
    }
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

class Nodes {
    users: AllResponse;
    pages: AllResponse;
    events: AllResponse;
    places: AllResponse;
    groups: AllResponse;
    [index: string]: AllResponse;
}

class VisibleItem {
    constructor() {
        this.queryAll = new QueryAll();
        this.querySpecific = new QuerySpecific();
    }

    select(arg: string) {
        this.queryAll.isVisible = false;
        this.querySpecific.isVisible = false;

        this[arg].isVisible = true;
    }

    queryAll: QueryAll;
    querySpecific: QuerySpecific;
    [index: string]: any;
}

class QueryAll {
    constructor() {
        this.isVisible = true;
        this.showProgressBar = false;
        this.showNodes = true;
        this.showFavorites = false;
    }

    select(arg: string) {
        this.showProgressBar = false;
        this.showNodes = false;
        this.showFavorites = false;

        this[arg] = true;
    }

    isVisible: boolean;
    showProgressBar: boolean;
    showNodes: boolean;
    showFavorites: boolean;
    [index: string]: any;
}

class QuerySpecific {
    constructor() {
        this.isVisible = false;
        this.showProgressBar = false;
        this.showResults = true;
    }

    select(arg: string) {
        this.showProgressBar = false;
        this.showResults = false;

        this[arg] = true;
    }

    isVisible: boolean;
    showProgressBar: boolean;
    showResults: boolean;
    [index: string]: any;
}

interface AngularScope {
    activeNode: NodeData | null,
    nodes: Nodes,
    keyword: string,
    visibleItem: VisibleItem,
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
    $scope.activeNode = null;
    $scope.keyword = '';
    $scope.visibleItem = new VisibleItem();

    $scope.clearClicked = function () {
        $scope.keyword = '';
        $scope.nodes = new Nodes();
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

        let url = `http://graphsearch.yj83leetest.space/fb_graph_search_json.php?target=all&keyword=${$scope.keyword}`;

        // TODO: use promise all
        $scope.nodes = new Nodes();
        type Responses = [
            { data: AllResponse },
            { data: AllResponse },
            { data: AllResponse },
            { data: AllResponse },
            { data: AllResponse }
            ]
        Promise.all([
            $http.get(url + '&type=user'),
            $http.get(url + '&type=page'),
            $http.get(url + '&type=event'),
            $http.get(url + '&type=place'),
            $http.get(url + '&type=group'),
        ].map(p => p.catch((e: any) => e))).then((responses: Responses | any) => {
            // when $http.get failed, response.data will be null
            const [usersData, pagesData, eventsData, placesData, groupsData] = responses;
            $scope.nodes.users = usersData.data;
            $scope.nodes.pages = pagesData.data;
            $scope.nodes.events = eventsData.data;
            $scope.nodes.places = placesData.data;
            $scope.nodes.groups = groupsData.data;
            console.log(groupsData);

            $scope.visibleItem.select('queryAll');
            if ($scope.activeType === 'favorites') {
                $scope.visibleItem.queryAll.select('showFavorites');
            } else {
                $scope.visibleItem.queryAll.select('showNodes');
                // needed code blow to correctly rerender
                $scope.$apply();
            }
        }).catch((reason: any) => {
            alert('error');
            console.log(reason);

            if ($scope.activeType === 'favorites') {
                $scope.visibleItem.queryAll.select('showFavorites');
            } else {
                $scope.visibleItem.queryAll.select('showNodes');
            }
        });

        $scope.visibleItem.select('queryAll');
        if ($scope.activeType === 'favorites') {
            $scope.visibleItem.queryAll.select('showFavorites');
        } else {
            $scope.visibleItem.queryAll.select('showProgressBar');
        }

        showFirstAlbum();
    };

    // keep track of favorites
    const favorites = localStorage.getItem('favorites');
    $scope.favorites = (favorites !== null) ? JSON.parse(favorites) : {};

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
        delete $scope.favorites[Number(node.id)];
        localStorage.setItem('favorites', JSON.stringify($scope.favorites));
    };


    $scope.detailsClicked = function (node: NodeData) {
        $scope.activeNode = node;
        $scope.visibleItem.select('querySpecific');
        $scope.visibleItem.querySpecific.select('showProgressBar');

        $http({
            method: 'Get',
            url: 'http://graphsearch.yj83leetest.space/fb_graph_search_json.php?target=specific&id=' + node.id,
        }).then(function successCallback(response: { data: SpecificResponse }) {
                $scope.visibleItem.select('querySpecific');
                $scope.visibleItem.querySpecific.select('showResults');
                $scope.detail = response.data;
            }, function errorCallback() {
                alert('error');
            },
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
            alert('error');
            console.log(response);
        }

        if ($scope.nodes === null) {
            return;
        }

        $http.get($scope.nodes[$scope.activeType].paging!.next).then(function (response: { data: AllResponse }) {
            updateNodesData(response.data);
            $scope.visibleItem.queryAll.select('showNodes');
        }, errorCallback);
        $scope.visibleItem.queryAll.select('showProgressBar');
    };

    $scope.previousClicked = function () {
        function errorCallback(response: string) {
            alert('error');
            console.log(response);
        }

        if ($scope.nodes === null) {
            return;
        }

        $http.get($scope.nodes[$scope.activeType].paging!.previous).then(function (response: { data: AllResponse }) {
            updateNodesData(response.data);
            $scope.visibleItem.queryAll.select('showNodes');
        }, errorCallback);
        $scope.visibleItem.queryAll.select('showProgressBar');
    };

    function updateNodesData(response: AllResponse) {
        if (response.data.length === 0) {
            // maybe bug of fb api, do nothing
            alert('bug of fb, no data in next page');
            return;
        }

        if ($scope.nodes === null) {
            return;
        }

        $scope.nodes[$scope.activeType] = response;
    }

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
    };
}).filter('notEmpty', function () { // http://stackoverflow.com/a/23396452
    return function (obj: object) {
        for (const property in obj) {
            if (obj.hasOwnProperty(property)) {
                return true;
            }
        }
        return false;
    };
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
                });
            },
        };
    },
]);
