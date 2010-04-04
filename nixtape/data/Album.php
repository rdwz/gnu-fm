<?php

/* GNU FM -- a free network service for sharing your music listening habits

   Copyright (C) 2009 Free Software Foundation, Inc

   This program is free software: you can redistribute it and/or modify
   it under the terms of the GNU Affero General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU Affero General Public License for more details.

   You should have received a copy of the GNU Affero General Public License
   along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

require_once($install_path . '/database.php');
require_once($install_path . '/data/Artist.php');
require_once($install_path . '/data/Track.php');
require_once($install_path . "/utils/resolve-external.php");
require_once($install_path . '/utils/linkeddata.php');

/**
 * Represents album data
 *
 * General album attributes are accessible as public variables.
 * Lists of tracks are only generated when requested.
 */
class Album {

	public $name, $artist_name, $mbid, $releasedate, $image;
	public $id;

	/**
	 * Album constructor
	 *
	 * @param string name The name of the album to load
	 * @param string artist The name of the artist who recorded this album
	 */
	function __construct($name, $artist) {
		global $adodb;
		$adodb->SetFetchMode(ADODB_FETCH_ASSOC);
		$r = $adodb->CacheGetRow(1200,
			'SELECT name, artist_name, mbid, image, releasedate FROM Album WHERE '
			. 'name = ' . $adodb->qstr($name) . ' AND '
			. 'artist_name = ' . $adodb->qstr($artist));
		if(!$r) {
			$this->name = 'No such album: ' . $name;
		} else {
			$row = sanitize($r);
			$this->name = $row['name'];
			$this->mbid = $row['mbid'];
			$this->artist_name = $row['artist_name'];
			$this->releasedate = $row['releasedate'];
			$this->image = resolve_external_url($row['image']);

			$this->id = identifierAlbum(null, $this->artist_name, null, $this->name, null, null, null, $this->mbid);

			// this	hack brought to	you by	mattl
			//if ($row['image'] == ''){
			//go_get_album_art($this->artist_name, $this->name);
			//}
			// mattl hack ovar

			if($this->image == '') {
				$this->image = false;
			}
		}

	}

	function getPlayCount() {
		global $adodb;
		$adodb->SetFetchMode(ADODB_FETCH_ASSOC);
		try {
		$count = $adodb->CacheGetOne(600,
			'SELECT COUNT(*) AS scrobbles FROM Scrobbles JOIN Track ON Scrobbles.track = Track.name WHERE Scrobbles.artist = '
			. $adodb->qstr($this->artist_name) . ' AND Track.album_name ='
			. $adodb->qstr($this->name));
		}
		catch (exception $e) {
			reportError($res->getMessage());
			$c = 0;
		}
		if (!$count) {
			$c = 0;
		} else {
			$c = $count;
		}
		return $c;
	}

	/**
	 * Retrieves all the tracks in an album
	 *
	 * @return An array of Track objects
	 */
	function getTracks() {
		global $adodb;
		$adodb->SetFetchMode(ADODB_FETCH_ASSOC);
		$res = $adodb->CacheGetAll(600, 'SELECT name, artist_name FROM Track WHERE artist_name = '
			. $adodb->qstr($this->artist_name) . ' AND album_name = '
			. $adodb->qstr($this->name));
		foreach($res as &$row) {
			$tracks[] = new Track($row['name'], $row['artist_name']);
		}

		return $tracks;
	}

	/**
	 * Gives the URL for this album
	 *
	 * @return A string containing the URL of this album
	 */
	function getURL() {
		return Server::getAlbumURL($this->artist_name, $this->name);
	}

	/**
	 * Get an albums's most used tags
	 * 
	 * @param int $limit The number of tags to return (defaults to 10)
	 * @return An array of tags
	 */
	function getTopTags($limit=10) {
		global $adodb;

		$res = $adodb->CacheGetAll(600, 'SELECT tag, COUNT(tag) AS freq FROM tags WHERE '
			. ' artist = ' . $adodb->qstr($this->artist_name)
			. ' AND album = ' . $adodb->qstr($this->name)
			. ' GROUP BY tag ORDER BY freq DESC '
			. ' LIMIT ' . $limit);

		return $res;
	}


    /*
     * Return Album Art URL from Wikipedia
     * @param string Album
     * @param string Artist
     * @param bool Save info to Album table.
     * @param string Wikipedia API URL
     * @return an object with the url and usage_url properties
     */
    function WikipediaAlbumArt ($album_name, $artist_name, $save = false, $api_url = 'http://en.wikipedia.org/w/api.php') {
        global $adodb;
        /*
         * Search query string
         */
        $album_name  = mb_convert_case($album_name, 'UTF-8');
        $artist_name = mb_convert_case($artist_name, 'UTF-8');
        $get_params  = array(
            'action'    => 'query',
            'format'    => 'php',
            'redirects' => true,
            'list'      => 'search',
            'srsearch'  => "$album_name $artist_name album",
            'srlimit'   => 10
        );

        try {
            if (is_null($album_name)) throw new Exception('No album name provided.');

            $search_url = $api_url . '?' . http_build_query($get_params);

            $open = fopen($search_url, 'r');

            if ($open == false) throw new Exception('Can\'t open Search URL');

            $search_results = unserialize(stream_get_contents($open));
            fclose($open);

            if (!isset($search_results['query']['search']) || count($search_results['query']['search']) == 0)
                return false;

            $results = array();

            foreach ($search_results['query']['search'] as $id => $page) {
                switch ($page['title']) {
                case ($album_name):
                    $weight = 0.5;
                    break;
                case ("$album_name (album)"):
                    $weight = 0.75;
                    break;
                case ("$album_name ($artist_name album)"):
                    $weight = 1;
                    break;
                default:
                    $weight = 0;
                }

                if ($weight > 0)
                    $results[$page['title']] = $weight;
            }

            if (count($results) > 0) {
                # order by weight
                # highest gets on bottom
                asort($results);
                end($results);

                $possible_cover = key($results);

                # Cover search query string
                $cover_params = array(
                    'action'    => 'query',
                    'format'    => 'php',
                    'redirects' => true,
                    'generator' => 'images',
                    'titles'    => $possible_cover,
                    'prop'      => 'imageinfo',
                    'iiprop'    => 'url'
                );

                $cover_search_url = $api_url . '?' . http_build_query($cover_params);
                $open_cover_url   = fopen($cover_search_url, 'r');

                if ($open_cover_url == false) throw new Exception ('Can\'t open Cover Search URL');
                $cover_search_results = unserialize(stream_get_contents($open_cover_url));
                fclose($open_cover_url);

                if (!isset($cover_search_results['query']['pages']) || count($cover_search_results['query']['pages']) == 0)
                    return false;

                foreach ($cover_search_results['query']['pages'] as $image_id => $image) {
                    # Wikipedia covers are mostly JPEG images.
                    # Gets the first image (hard guess!)
                    if (preg_match('/\.jpg$/i', $image['title']) == 1) {
                        $cover = $image['imageinfo'][0];
                        break;
                    }
                }

                /*
                 * Save the info if $save = true
                 */
                if ($save) {
                    $adodb->SetFetchMode(ADODB_FETCH_ASSOC);
                    $album   = $adodb->qstr($album_name);
                    $artist  = $adodb->qstr($artist_name);
                    $license = $adodb->qstr($cover['descriptionurl']);
                    $image   = $adodb->qstr($cover['url']);

		            $sql = ('UPDATE Album SET image = '
                         . ($image) . ', '
                         . ' artwork_license = '
                         . ($license) . ' WHERE artist_name = '. ($artist)
                         . ' AND name = '	. ($album));

                    $res = $adodb->Execute($sql);
                }

                return $cover['url'];

            } else {
                return false;
            }


        } catch (exception $e) {
            reportError($e->getMessage());
        }

        return $album_art_url;
    }
}



function go_get_album_art($artist, $album){
	global $adodb;
	$adodb->SetFetchMode(ADODB_FETCH_ASSOC);

	$Access_Key_ID = '1EST86JB355JBS3DFE82'; // this is mattl's personal key :)

	$SearchIndex='Music';
	$Keywords=urlencode($artist.' '.$album);
	$Operation = 'ItemSearch';
	$Version = '2007-07-16';
	$ResponseGroup = 'ItemAttributes,Images';
	$request=
		'http://ecs.amazonaws.com/onca/xml'
		. '?Service=AWSECommerceService'
		. '&AssociateTag=' . $Associate_tag
		. '&AWSAccessKeyId=' . $Access_Key_ID
		. '&Operation=' . $Operation
		. '&Version=' . $Version
		. '&SearchIndex=' . $SearchIndex
		. '&Keywords=' . $Keywords
		. '&ResponseGroup=' . $ResponseGroup;

	$aws_xml = simplexml_load_file($request) or die('xml response not loading');

	$image = $aws_xml->Items->Item->MediumImage->URL;
	$URI = $aws_xml->Items->Item->DetailPageURL;

	if ($image) {

		if ($license == '') { $license = 'amazon'; }

		$license = $adodb->qstr($license);
		$image = $adodb->qstr($image);
		$album = $adodb->qstr($album);
		$artist = $adodb->qstr($artist);

		$sql = ('UPDATE Album SET image = '
			. ($image) . ', '
			. ' artwork_license = '
			. ($license) . ' WHERE artist_name = '. ($artist)
			. ' AND name = '	. ($album));

		try {
			$res = $adodb->Execute($sql);
		}
		catch (exception $e) {
			die('FAILED ' . $e->getMessage() . ' query was :' . $sql);
		}
	}
}
