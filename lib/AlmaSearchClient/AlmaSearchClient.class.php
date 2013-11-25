<?php

/**
 * @file
 * Provides a client for the Axiell Alma library information webservice.
 */
class AlmaSearchClient {

  /**
   * @var AlmaClientBaseURL
   * The base server URL to run the requests against.
   */
  private $base_url;
  /**
   * The salt which will be used to scramble sensitive information across
   * all requests for the page load.
   */
  private static $salt;

  /**
   * Constructor, checking if we have a sensible value for $base_url.
   */
  function __construct($base_url) {
    if (stripos($base_url, 'http') === 0 && filter_var($base_url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED)) {
      $this->base_url = $base_url;
    } else {
      // TODO: Use a specialised exception for this.
      throw new Exception('Invalid base URL: ' . $base_url);
    }

    self::$salt = mt_rand();
  }

  /**
   * Perform request to the Alma server.
   *
   * @param string $method
   *    The REST method to call e.g. 'patron/status'. borrCard and pinCode
   *    are required for all request related to library patrons.
   * @param array $params
   *    Query string parameters in the form of key => value.
   * @param boolean $check_status
   *    Check the status element, and throw an exception if it is not ok.
   * @return DOMDocument
   *    A DOMDocument object with the response.
   */
  public function request($method, $params = array(), $check_status = TRUE) {
    $startTime = explode(' ', microtime());
    // For use with a non-Drupal-system, we should have a way to swap
    // the HTTP client out.
    $request = drupal_http_request(url($this->base_url . $method, array('query' => $params)), array('secure_socket_transport' => 'sslv3'));
    $stopTime = explode(' ', microtime());
    // For use with a non-Drupal-system, we should have a way to swap
    // logging and logging preferences out.
    if (variable_get('alma_enable_logging', FALSE)) {
      $seconds = floatval(($stopTime[1] + $stopTime[0]) - ($startTime[1] + $startTime[0]));

      // Filter params to avoid logging sensitive data.
      // This can be disabled by setting alma_logging_filter_params = 0. There is no UI for setting this variable
      // It is intended for settings.php in development environments only.
      $params = (variable_get('alma_logging_filter_params', 1)) ? self::filter_request_params($params) : $params;

      // Log the request
      watchdog('alma', 'Sent request: @url (@seconds s)', array('@url' => url($this->base_url . $method, array('query' => $params)), '@seconds' => $seconds), WATCHDOG_DEBUG);
    }

    if ($request->code == 200) {
      // Since we currently have no need for the more advanced stuff
      // SimpleXML provides, we'll just use DOM, since that is a lot
      // faster in most cases.
      $doc = new DOMDocument();
      $doc->loadXML($request->data);
      if (!$check_status || $doc->getElementsByTagName('status')->item(0)->getAttribute('value') == 'ok') {
        return $doc;
      } else {
        $message = $doc->getElementsByTagName('status')->item(0)->getAttribute('key');
        switch ($message) {
          case '':
          default:
            throw new AlmaSearchClientCommunicationError('Status is not okay: ' . $message);
        }
      }
    } else {
      throw new AlmaSearchClientHTTPError('Request error: ' . $request->code . $request->error);
    }
  }

  /**
   * Perform request to the Alma server.
   *
   * @param string $method
   *    The REST method to call e.g. 'patron/status'. borrCard and pinCode
   *    are required for all request related to library patrons.
   * @param array $params
   *    Query string parameters in the form of key => value.
   * @param boolean $check_status
   *    Check the status element, and throw an exception if it is not ok.
   * @return DOMDocument
   *    A DOMDocument object with the response.
   */
  public function multi_request($method, $params = array(), $check_status = TRUE) {
    $curl_sessions = array();
    foreach ($params as $param) {
      $url = url($this->base_url . $method, array('query' => $param));
      $curl_sessions[] = $this->get_curl_session($url);
    }
    $startTime = explode(' ', microtime());
    // For use with a non-Drupal-system, we should have a way to swap
    // the HTTP client out.
    //$url = url($this->base_url . $method, array('query' => $params));
    $request = curl_multi($curl_sessions);
    file_put_contents("/home/quickstart/work/debug/debuggenremulti2.txt", print_r($request, TRUE), FILE_APPEND);

    $stopTime = explode(' ', microtime());
    // For use with a non-Drupal-system, we should have a way to swap
    // logging and logging preferences out.
    if (variable_get('alma_enable_logging', FALSE)) {
      $seconds = floatval(($stopTime[1] + $stopTime[0]) - ($startTime[1] + $startTime[0]));

      // Filter params to avoid logging sensitive data.
      // This can be disabled by setting alma_logging_filter_params = 0. There is no UI for setting this variable
      // It is intended for settings.php in development environments only.
      $params = (variable_get('alma_logging_filter_params', 1)) ? self::filter_request_params($params) : $params;

      // Log the request
      watchdog('alma', 'Sent request: @url (@seconds s)', array('@url' => url($this->base_url . $method, array('query' => $params)), '@seconds' => $seconds), WATCHDOG_DEBUG);
    }

    if ($request) {
      $docs = array();
      foreach ($request as $rec) {
        // Since we currently have no need for the more advanced stuff
        // SimpleXML provides, we'll just use DOM, since that is a lot
        // faster in most cases.
        $doc = new DOMDocument();
        $doc->loadXML($rec);
        if (!$check_status || $doc->getElementsByTagName('status')->item(0)->getAttribute('value') == 'ok') {
          $docs = $doc;
        } else {
          $message = $doc->getElementsByTagName('status')->item(0)->getAttribute('key');
          switch ($message) {
            case '':
            default:
              throw new AlmaSearchClientCommunicationError('Status is not okay: ' . $message);
          }
        }
      }
      return $docs; 
    } else {
      throw new AlmaSearchClientHTTPError('Request error: ' . $request->code . $request->error);
    }
  }

  public function get_curl_session($url) {
    $curl_session = array();    
    $curl_session['endpoint'] = $url;
    $agent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.0.8) Gecko/2009032609 Firefox/3.0.8';
    $curl_options = array(
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_USERAGENT => $agent,
      CURLOPT_SSL_VERIFYPEER => false,
    );

    $curl_session['options'] = $curl_options;
    
   file_put_contents("/home/quickstart/work/debug/debuggenremultcurl1.txt", print_r($curl_session, TRUE), FILE_APPEND);
    return $curl_session;
  }

  /**
   * Filters sensitive information in request parameters allowing the values to be logged
   *
   * @param array $params An array of request information
   *
   * @return array
   *    An array of filtered request information
   */
  private static function filter_request_params($params) {
    // Scramble sensitive information
    $sensitive = array('borrCard', 'pinCode', 'pinCodeChange', 'address', 'emailAddress');

    $log_params = array();
    foreach ($params as $key => $value) {
      if (in_array($key, $sensitive)) {
        // Replace the value with a scrambled version generated using md5() and
        // the static salt. This way all requests generated by the same page
        // load can be grouped
        $value = substr(md5($value . self::$salt), 0, strlen($value));
      }
      $log_params[$key] = $value;
    }

    return $log_params;
  }

  /**
   * Searches in the DDELibra LMS. Returnes ids from the search result
   *
   * @param string $search_text
   *    Search string in CCL
   * @param string $search_type
   *   One of  native | fullText | namedList. Native is the DDLibra's native search language (CCL)
   * @param $start_no
   *    Where in the search result to start returning the asked for number of records.
   * @param $number_of_records
   *    Number of returned records
   */
  public function run_lms_search($search_text, $search_type = 'native', $start_no = 1, $number_of_records = 30) {
    $params = array(
      'searchText' => $search_text,
      'searchType' => $search_type,
      'startNo' => $start_no,
      'nofRecords' => $number_of_records
    );
    $doc = $this->request('catalogue/fulltextsearch', $params, FALSE);
    $data = array(
      'request_status' => $doc->getElementsByTagName('status')->item(0)->getAttribute('value'),
      'number_of_records' => $doc->getElementsByTagName('nofRecords')->item(0)->nodeValue,
      'number_of_records_total' => $doc->getElementsByTagName('nofRecordsTotal')->item(0)->nodeValue,
      'start_number' => $doc->getElementsByTagName('startNo')->item(0)->nodeValue,
      'stop_number' => $doc->getElementsByTagName('stopNo')->item(0)->nodeValue,
      'alma_ids' => array(),
    );
    foreach ($doc->getElementsByTagName('catalogueRecord') as $elem) {
      $data['alma_ids'][] = $elem->getAttribute('id');
    }

    return $data;
  }

  /**
   * Get details about one or more catalogue record.
   */
  public function catalogue_record_detail($alma_ids) {
    $params = array();
    $offset = 0;
    while (count($alma_ids) > $offset) {
      $slice = array_slice($alma_ids, $offset, 50, true);
      $params[] = array(
        'catalogueRecordKey' =>  join(',', $slice),
      );
      $offset += 50;
    }
    file_put_contents("/home/quickstart/work/debug/debuggenremulti3.txt", print_r($params, TRUE), FILE_APPEND);
//    $params = array(
//      'catalogueRecordKey' => $alma_ids,
//    );
    $docs = $this->multi_request('catalogue/detail', $params, FALSE);
    $data = array(
      'request_status' => $doc->getElementsByTagName('status')->item(0)->getAttribute('value'),
      'records' => array(),
    );

    foreach ($docs as $doc) {
      foreach ($doc->getElementsByTagName('detailCatalogueRecord') as $elem) {
        $record = AlmaSearchClient::process_catalogue_record_details($elem);
        $data['records'][$record['alma_id']] = $record;
      }
    }
    return $data;
  }

  /**
   * Helper function for processing the catalogue records.
   */
  private static function process_catalogue_record_details($elem) {
    $record = array(
      'alma_id' => $elem->getAttribute('id'),
      'target_audience' => $elem->getAttribute('targetAudience'),
      'show_reservation_button' => ($elem->getAttribute('showReservationButton') == 'yes') ? TRUE : FALSE,
      'reservation_count' => $elem->getAttribute('nofReservations'),
      'loan_count_year' => $elem->getAttribute('nofLoansYear'),
      'loan_count_total' => $elem->getAttribute('nofLoansTotal'),
      'available_count' => $elem->getAttribute('nofAvailableForLoan'),
      'title_series' => $elem->getAttribute('titleSeries'),
      'title_original' => $elem->getAttribute('titleOriginal'),
      'resource_type' => $elem->getAttribute('resourceType'),
      'publication_year' => $elem->getAttribute('publicationYear'),
      'media_class' => $elem->getAttribute('mediaClass'),
      'extent' => $elem->getAttribute('extent'),
      'edition' => $elem->getAttribute('edition'),
      'category' => $elem->getAttribute('category'),
    );

    foreach ($elem->getElementsByTagName('author') as $item) {
      $record['authors'][] = $item->getAttribute('value');
    }

    foreach ($elem->getElementsByTagName('description') as $item) {
      $record['descriptions'][] = $item->getAttribute('value');
    }

    foreach ($elem->getElementsByTagName('isbn') as $item) {
      $record['isbns'][] = $item->getAttribute('value');
    }

    foreach ($elem->getElementsByTagName('language') as $item) {
      $record['languages'][] = $item->getAttribute('value');
    }

    foreach ($elem->getElementsByTagName('note') as $item) {
      $record['notes'][] = $item->getAttribute('value');
    }

    foreach ($elem->getElementsByTagName('title') as $item) {
      $record['titles'][] = $item->getAttribute('value');
    }

    if ($record['media_class'] != 'periodical') {
      $record['holdings'] = AlmaSearchClient::process_catalogue_record_holdings($elem);
    }
    // Periodicals are nested holdings, which we want to keep that way.
    else {
      foreach ($elem->getElementsByTagName('compositeHoldings') as $holdings) {
        foreach ($holdings->childNodes as $year_holdings) {
          $year = $year_holdings->getAttribute('value');
          foreach ($year_holdings->childNodes as $issue_holdings) {
            $issue = $issue_holdings->getAttribute('value');
            $holdings = AlmaSearchClient::process_catalogue_record_holdings($issue_holdings);
            $record['holdings'][$year][$issue] = $holdings;
            $issue_list = array(
              'available_count' => 0,
              'branches' => array(),
              'reservable' => $holdings[0]['reservable'],
            );

            // Also create an array with the totals for each issue.
            foreach ($holdings as $holding) {
              if ($holding['available_count'] > 0) {
                $issue_list['available_count'] += (int) $holding['available_count'];
                if (isset($issue_list['branches'][$holding['branch_id']])) {
                  $issue_list['branches'][$holding['branch_id']] += (int) $holding['available_count'];
                } else {
                  $issue_list['branches'][$holding['branch_id']] = (int) $holding['available_count'];
                }
              }
            }

            $record['issues'][$year][$issue] = $issue_list;
          }
        }
      }
    }

    return $record;
  }
  
    /**
   * Helper function for processing the catalogue record holdings.
   */
  private static function process_catalogue_record_holdings($elem) {
    $holdings = array();

    foreach ($elem->getElementsByTagName('holding') as $item) {
      $holdings[] = array(
        'reservable' => $item->getAttribute('reservable'),
        'status' => $item->getAttribute('status'),
        'ordered_count' => (int) $item->getAttribute('nofOrdered'),
        'checked_out_count' => (int) $item->getAttribute('nofCheckedOut'),
        'reference_count' => (int) $item->getAttribute('nofReference'),
        'total_count' => (int) $item->getAttribute('nofTotal'),
        'collection_id' => $item->getAttribute('collectionId'),
        'sublocation_id' => $item->getAttribute('subLocationId'),
        'location_id' => $item->getAttribute('locationId'),
        'department_id' => $item->getAttribute('departmentId'),
        'branch_id' => $item->getAttribute('branchId'),
        'organisation_id' => $item->getAttribute('organisationId'),
        'available_count' => (int) $item->getAttribute('nofAvailableForLoan'),
        'shelf_mark' => $item->getAttribute('shelfMark'),
        'available_from' => $item->getAttribute('firstLoanDueDate'),
      );
    }

    return $holdings;
  }

}

/**
 * Define exceptions for different error conditions inside the Alma client.
 */
class AlmaSearchClientInvalidURLError extends Exception {
  
}

class AlmaSearchClientHTTPError extends Exception {
  
}

class AlmaSearchClientCommunicationError extends Exception {
  
}

