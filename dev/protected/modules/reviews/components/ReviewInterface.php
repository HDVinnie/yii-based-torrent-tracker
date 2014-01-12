<?php

abstract class ReviewInterface {

	/**
	 * Method to get type of reviews (video, audio, games etc)
	 * @return string
	 */
	abstract function getType ();

	abstract function getTitle ();

	abstract function getNeededFields ();

	abstract function getId ();

	/**
	 * @param $args
	 *
	 * @return mixed
	 */
	abstract protected function getApiData ( $args );

	/**
	 * @param EActiveRecord $model
	 * @param               $attrs
	 *
	 * @return mixed
	 */
	public function getReviewData ( EActiveRecord $model, $attrs ) {
		$db = Yii::app()->getDb();

		$sql = 'SELECT * FROM {{reviews}} WHERE modelId = :modelId AND modelName = :modelName AND apiName = :apiName AND mtime > :mtime';
		$comm = $db->createCommand($sql);
		$comm->bindValue(':modelId', $model->getPrimaryKey());
		$comm->bindValue(':modelName', $model->resolveClassName());
		$comm->bindValue(':apiName', $this->getId());
		$comm->bindValue(':mtime', time() - 1 * 24 * 60 * 60);

		if ( $dataReader = $comm->queryRow() ) {
			return $dataReader['ratingText'];
		}

		/**
		 * TODO: убрать в консоль, чтобы не было задержек при открытии страниц
		 */
		return $this->sendData($model, $this->getApiData($attrs));
	}

	/**
	 * @param EActiveRecord $model
	 * @param               $data
	 *
	 * @return mixed
	 */
	public function sendData ( EActiveRecord $model, $data ) {
		$db = Yii::app()->getDb();

		$sql = 'SELECT * FROM {{reviews}} WHERE modelId = :modelId AND modelName = :modelName AND apiName = :apiName';
		$comm = $db->createCommand($sql);
		$comm->bindValue(':modelId', $model->getPrimaryKey());
		$comm->bindValue(':modelName', $model->resolveClassName());
		$comm->bindValue(':apiName', $this->getId());

		if ( $dataReader = $comm->queryRow() ) {
			if ( $data === false ) {
				$sql = 'UPDATE {{reviews}} SET mtime = :mtime WHERE modelId = :modelId AND modelName = :modelName AND apiName = :apiName';
				$comm = $db->createCommand($sql);
			}
			else {
				$sql = 'UPDATE {{reviews}} SET mtime = :mtime, ratingText = :ratingText WHERE modelId = :modelId AND modelName = :modelName AND apiName = :apiName';
				$comm = $db->createCommand($sql);
				$comm->bindValue(':ratingText', $data);
			}
			$comm->bindValue(':modelId', $model->getPrimaryKey());
			$comm->bindValue(':modelName', $model->resolveClassName());
			$comm->bindValue(':apiName', $this->getId());
			$comm->bindValue(':mtime', time());
		}
		else {
			$sql = 'INSERT INTO {{reviews}} (mtime, modelId, modelName, apiName, ratingText) VALUES(:mtime, :modelId, :modelName, :apiName, :ratingText)';
			$comm = $db->createCommand($sql);
			$comm->bindValue(':modelId', $model->getPrimaryKey());
			$comm->bindValue(':modelName', $model->resolveClassName());
			$comm->bindValue(':apiName', $this->getId());
			$comm->bindValue(':mtime', time());
			$comm->bindValue(':ratingText', ($data === false ? '' : $data));
		}
		$comm->execute();

		return $data;
	}

	/**
	 * @param       $url
	 * @param array $options
	 * @param bool  $parseJson
	 *
	 * @return mixed|object
	 * @throws CException
	 */
	public function makeRequest ( $url, $options = array(), $parseJson = true ) {
		$ch = $this->initRequest($url, $options);

		$result = curl_exec($ch);
		$headers = curl_getinfo($ch);

		if ( curl_errno($ch) > 0 ) {
			throw new CException(curl_error($ch), curl_errno($ch));
		}

		if ( $headers['http_code'] != 200 ) {
			Yii::log('Invalid response http code: ' . $headers['http_code'] . '.' . PHP_EOL . 'URL: ' . $url . PHP_EOL . 'Options: ' . var_export($options,
					true) . PHP_EOL . 'Result: ' . $result . PHP_EOL . 'Headers: ' . var_export(curl_getinfo($ch),
					true),
				CLogger::LEVEL_ERROR);
			throw new CException(Yii::t('reviewsModule.common',
				'Invalid response http code: {code}.',
				array('{code}' => $headers['http_code'])), $headers['http_code']);
		}

		curl_close($ch);

		if ( $parseJson ) {
			$result = $this->parseJson($result);
		}

		return $result;
	}

	/**
	 * Initializes a new session and return a cURL handle.
	 *
	 * @param string $url     url to request.
	 * @param array  $options HTTP request options. Keys: query, data, referer.
	 *
	 * @return cURL handle.
	 */
	protected function initRequest ( $url, $options = array() ) {
		$ch = curl_init();
		//curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // error with open_basedir or safe mode
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

		if ( isset($options['referer']) ) {
			curl_setopt($ch, CURLOPT_REFERER, $options['referer']);
		}

		if ( isset($options['headers']) ) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
		}

		if ( isset($options['useragent']) ) {
			curl_setopt($ch, CURLOPT_USERAGENT, $options['useragent']);
		}

		if ( isset($options['query']) ) {
			$url_parts = parse_url($url);
			if ( isset($url_parts['query']) ) {
				$query = $url_parts['query'];
				if ( strlen($query) > 0 ) {
					$query .= '&';
				}
				$query .= http_build_query($options['query']);
				$url = str_replace($url_parts['query'], $query, $url);
			}
			else {
				$url_parts['query'] = $options['query'];
				$new_query = http_build_query($url_parts['query']);
				$url .= '?' . $new_query;
			}
		}

		if ( isset($options['data']) ) {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $options['data']);
		}

		curl_setopt($ch, CURLOPT_URL, $url);
		return $ch;
	}

	/**
	 * Parse response from {@link makeRequest} in json format and check OAuth errors.
	 *
	 * @param string $response Json string.
	 *
	 * @return object result.
	 */
	protected function parseJson ( $response ) {
		try {
			$result = json_decode($response);
			$error = $this->fetchJsonError($result);
			if ( !isset($result) ) {
				throw new CException(Yii::t('reviewsModule.common',
					'Invalid response format.' . PHP_EOL . 'Response: {response}',
					array('{response}' => var_export($response, true))), 500);
			}
			else {
				if ( isset($error) && !empty($error['message']) ) {
					throw new CException($error['message'], $error['code']);
				}
				else {
					return $result;
				}
			}
		} catch ( Exception $e ) {
			throw new CException($e->getMessage(), $e->getCode());
		}
	}

	/**
	 * Returns the error info from json.
	 *
	 * @param stdClass $json the json response.
	 *
	 * @return array the error array with 2 keys: code and message. Should be null if no errors.
	 */
	protected function fetchJsonError ( $json ) {
		if ( isset($json->error) ) {
			return array(
				'code'    => 500,
				'message' => 'Unknown error occurred.',
			);
		}
		else {
			return null;
		}
	}
}