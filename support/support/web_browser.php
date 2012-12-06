<?php
	// CubicleSoft PHP web browser state emulation class.
	// (C) 2012 CubicleSoft.  All Rights Reserved.

	// Requires the CubicleSoft PHP HTTP functions for HTTP/HTTPS.
	class WebBrowser
	{
		private $data;

		public function __construct($prevstate = array())
		{
			$this->ResetState();
			$this->SetState($prevstate);
		}

		public function ResetState()
		{
			$this->data = array(
				"allowedprotocols" => array("http" => true, "https" => true),
				"allowedredirprotocols" => array("http" => true, "https" => true),
				"cookies" => array(),
				"referer" => "",
				"autoreferer" => true,
				"useragent" => "firefox",
				"followlocation" => true,
				"maxfollow" => 20,
				"httpopts" => array(),
			);
		}

		public function SetState($options = array())
		{
			$this->data = array_merge($this->data, $options);
		}

		public function GetState()
		{
			return $this->data;
		}

		public function Process($url, $profile = "auto", $tempoptions = array())
		{
			$startts = microtime(true);
			$redirectts = $startts;
			if (isset($tempoptions["timeout"]))  $timeout = $tempoptions["timeout"];
			else if (isset($this->data["httpopts"]["timeout"]))  $timeout = $this->data["httpopts"]["timeout"];
			else  $timeout = false;

			if (!isset($this->data["httpopts"]["headers"]))  $this->data["httpopts"]["headers"] = array();
			$this->data["httpopts"]["headers"] = HTTPNormalizeHeaders($this->data["httpopts"]["headers"]);
			unset($this->data["httpopts"]["method"]);
			unset($this->data["httpopts"]["write_body_callback"]);
			unset($this->data["httpopts"]["body"]);
			unset($this->data["httpopts"]["postvars"]);
			unset($this->data["httpopts"]["files"]);

			$httpopts = $this->data["httpopts"];
			$numfollow = $this->data["maxfollow"];
			$numredirects = 0;
			$totalrawsendsize = 0;

			if (!isset($tempoptions["headers"]))  $tempoptions["headers"] = array();
			$tempoptions["headers"] = HTTPNormalizeHeaders($tempoptions["headers"]);
			$referer = (isset($tempoptions["headers"]["Referer"]) ? $tempoptions["headers"]["Referer"] : $this->data["referer"]);

			// If a referrer is specified, use it to generate an absolute URL.
			if ($referer != "")  $url = ConvertRelativeToAbsoluteURL($referer, $url);

			$urlinfo = ExtractURL($url);

			do
			{
				if (!isset($this->data["allowedprotocols"][$urlinfo["scheme"]]) || !$this->data["allowedprotocols"][$urlinfo["scheme"]])
				{
					return array("success" => false, "error" => HTTPTranslate("Protocol '%s' is not allowed in '%s'.", $urlinfo["scheme"], $url), "errorcode" => "allowed_protocols");
				}

				$filename = HTTPExtractFilename($urlinfo["path"]);
				$pos = strrpos($filename, ".");
				$fileext = ($pos !== false ? strtolower(substr($filename, $pos + 1)) : "");

				// Set up some standard headers.
				$headers = array();
				$profile = strtolower($profile);
				$tempprofile = explode("-", $profile);
				if (count($tempprofile) == 2)
				{
					$profile = $tempprofile[0];
					$fileext = $tempprofile[1];
				}
				if (substr($profile, 0, 2) == "ie" || ($profile == "auto" && substr($this->data["useragent"], 0, 2) == "ie"))
				{
					if ($fileext == "css")  $headers["Accept"] = "text/css";
					else if ($fileext == "png" || $fileext == "jpg" || $fileext == "jpeg" || $fileext == "gif" || $fileext == "svg")  $headers["Accept"] = "image/png, image/svg+xml, image/*;q=0.8, */*;q=0.5";
					else if ($fileext == "js")  $headers["Accept"] = "application/javascript, */*;q=0.8";
					else if ($referer != "" || $fileext == "" || $fileext == "html" || $fileext == "xhtml" || $fileext == "xml")  $headers["Accept"] = "text/html, application/xhtml+xml, */*";
					else  $headers["Accept"] = "*/*";

					$headers["Accept-Language"] = "en-US";
					$headers["User-Agent"] = GetWebUserAgent(substr($profile, 0, 2) == "ie" ? $profile : $this->data["useragent"]);
				}
				else if ($profile == "firefox" || ($profile == "auto" && $this->data["useragent"] == "firefox"))
				{
					if ($fileext == "css")  $headers["Accept"] = "text/css,*/*;q=0.1";
					else if ($fileext == "png" || $fileext == "jpg" || $fileext == "jpeg" || $fileext == "gif" || $fileext == "svg")  $headers["Accept"] = "image/png,image/*;q=0.8,*/*;q=0.5";
					else if ($fileext == "js")  $headers["Accept"] = "*/*";
					else  $headers["Accept"] = "text/html, application/xhtml+xml, */*";

					$headers["Accept-Language"] = "en-us,en;q=0.5";
					$headers["Cache-Control"] = "max-age=0";
					$headers["User-Agent"] = GetWebUserAgent("firefox");
				}
				else if ($profile == "opera" || ($profile == "auto" && $this->data["useragent"] == "opera"))
				{
					// Opera has the right idea:  Just send the same thing regardless of the request type.
					$headers["Accept"] = "text/html, application/xml;q=0.9, application/xhtml+xml, image/png, image/webp, image/jpeg, image/gif, image/x-xbitmap, */*;q=0.1";
					$headers["Accept-Language"] = "en-US,en;q=0.9";
					$headers["Cache-Control"] = "no-cache";
					$headers["User-Agent"] = GetWebUserAgent("opera");
				}
				else if ($profile == "safari" || $profile == "chrome" || ($profile == "auto" && ($this->data["useragent"] == "safari" || $this->data["useragent"] == "chrome")))
				{
					if ($fileext == "css")  $headers["Accept"] = "text/css,*/*;q=0.1";
					else if ($fileext == "png" || $fileext == "jpg" || $fileext == "jpeg" || $fileext == "gif" || $fileext == "svg" || $fileext == "js")  $headers["Accept"] = "*/*";
					else  $headers["Accept"] = "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";

					$headers["Accept-Charset"] = "ISO-8859-1,utf-8;q=0.7,*;q=0.3";
					$headers["Accept-Language"] = "en-US,en;q=0.8";
					$headers["User-Agent"] = GetWebUserAgent($profile == "safari" || $profile == "chrome" ? $profile : $this->data["useragent"]);
				}

				if ($referer != "")  $headers["Referer"] = $referer;

				// Generate the final headers array.
				$headers = array_merge($headers, $httpopts["headers"], $tempoptions["headers"]);

				// Calculate the host and reverse host and remove port information.
				$host = (isset($headers["Host"]) ? $headers["Host"] : $urlinfo["host"]);
				$pos = strpos($host, "]");
				if (substr($host, 0, 1) == "[" && $pos !== false)
				{
					$host = substr($host, 0, $pos + 1);
				}
				else
				{
					$pos = strpos($host, ":");
					if ($pos !== false)  $host = substr($host, 0, $pos);
				}
				$dothost = $host;
				if (substr($dothost, 0, 1) != ".")  $dothost = "." . $dothost;

				// Append cookies and delete old, invalid cookies.
				$secure = ($urlinfo["scheme"] == "https");
				$cookiepath = $urlinfo["path"];
				if ($cookiepath == "")  $cookiepath = "/";
				$pos = strrpos($cookiepath, "/");
				if ($pos !== false)  $cookiepath = substr($cookiepath, 0, $pos + 1);
				$cookies = array();
				foreach ($this->data["cookies"] as $domain => $paths)
				{
					if (substr($domain, -strlen($dothost)) == $dothost)
					{
						foreach ($paths as $path => $cookies)
						{
							if (substr($path, 0, strlen($cookiepath)) == $cookiepath)
							{
								foreach ($cookies as $num => $info)
								{
									if (isset($info["expires_ts"]) && $this->GetExpiresTimestamp($info["expires_ts"]) < time())  unset($this->data["cookies"][$domain][$path][$num]);
									else if ($secure && isset($info["secure"]))  $cookies[$info["name"]] = $info["value"];
								}

								if (!count($this->data["cookies"][$domain][$path]))  unset($this->data["cookies"][$domain][$path]);
							}
						}

						if (!count($this->data["cookies"][$domain]))  unset($this->data["cookies"][$domain]);
					}
				}

				$cookies2 = array();
				foreach ($cookies as $name => $value)  $cookies2[] = rawurlencode($name) . "=" . rawurlencode($value);
				$headers["Cookie"] = implode("; ", $cookies2);

				// Generate the final options array.
				$options = array_merge($httpopts, $tempoptions);
				$options["headers"] = $headers;
				if ($timeout !== false)  $options["timeout"] = HTTPGetTimeLeft($startts, $timeout);

				// Process the request.
				$result = RetrieveWebpage($url, $options);
				$result["url"] = $url;
				$result["options"] = $options;
				$result["firstreqts"] = $startts;
				$result["numredirects"] = $numredirects;
				$result["redirectts"] = $redirectts;
				$totalrawsendsize += $result["rawsendsize"];
				$result["totalrawsendsize"] = $totalrawsendsize;
				unset($result["options"]["files"]);
				if (!$result["success"])  return array("success" => false, "error" => HTTPTranslate("Unable to retrieve content.  %s", $result["error"]), "info" => $result, "errorcode" => "retrievewebpage");

				// Set up structures for another round.
				if ($this->data["autoreferer"])  $this->data["referer"] = $url;
				if (isset($result["headers"]["Location"]) && $this->data["followlocation"])
				{
					$redirectts = microtime(true);

					unset($tempoptions["method"]);
					unset($tempoptions["write_body_callback"]);
					unset($tempoptions["body"]);
					unset($tempoptions["postvars"]);
					unset($tempoptions["files"]);

					$tempoptions["headers"]["Referer"] = $url;
					$url = $result["headers"]["Location"][0];

					// Generate an absolute URL.
					$url = ConvertRelativeToAbsoluteURL($referer, $url);

					$urlinfo2 = ExtractURL($url);

					if (!isset($this->data["allowedredirprotocols"][$urlinfo2["scheme"]]) && !$this->data["allowedredirprotocols"][$urlinfo2["scheme"]])
					{
						return array("success" => false, "error" => HTTPTranslate("Protocol '%s' is not allowed.  Server attempted to redirect to '%s'.", $urlinfo2["scheme"], $url), "info" => $result, "errorcode" => "allowed_redir_protocols");
					}

					if ($urlinfo2["host"] != $urlinfo["host"])
					{
						unset($tempoptions["headers"]["Host"]);
						unset($httpopts["headers"]["Host"]);
					}

					$urlinfo = $urlinfo2;
					$numredirects++;
				}

				// Handle any 'Set-Cookie' headers.
				if (isset($result["headers"]["Set-Cookie"]))
				{
					foreach ($result["headers"]["Set-Cookie"] as $cookie)
					{
						$items = explode("; ", $cookie);
						$item = trim(array_shift($items));
						if ($item != "")
						{
							$cookie2 = array();
							$pos = strpos($item, "=");
							if ($pos === false)
							{
								$cookie2["name"] = urldecode($item);
								$cookie2["value"] = "";
							}
							else
							{
								$cookie2["name"] = urldecode(substr($item, 0, $pos));
								$cookie2["value"] = urldecode(substr($item, $pos + 1));
							}

							$cookie = array();
							foreach ($items as $item)
							{
								$item = trim($item);
								if ($item != "")
								{
									$pos = strpos($item, "=");
									if ($pos === false)  $cookie[strtolower(trim(urldecode($item)))] = "";
									else  $cookie[strtolower(trim(urldecode(substr($item, 0, $pos))))] = urldecode(substr($item, $pos + 1));
								}
							}
							$cookie = array_merge($cookie, $cookie2);

							if (isset($cookie["expires"]))
							{
								$ts = GetHTTPDateTimestamp($cookie["expires"]);
								$cookie["expires_ts"] = gmdate("Y-m-d H:i:s", ($ts === false ? time() - 24 * 60 * 60 : $ts));
							}
							else if (isset($cookie["max-age"]))
							{
								$cookie["expires_ts"] = gmdate("Y-m-d H:i:s", time() + (int)$cookie["max-age"]);
							}
							else
							{
								unset($cookie["expires_ts"]);
							}

							if (!isset($cookie["domain"]))  $cookie["domain"] = $dothost;
							if (substr($cookie["domain"], 0, 1) != ".")  $cookie["domain"] = "." . $cookie["domain"];
							if (!isset($cookie["path"]))  $cookie["path"] = $cookiepath;
							$cookie["path"] = str_replace("\\", "/", $cookie["path"]);
							if (substr($cookie["path"], -1) != "/")  $cookie["path"] = "/";

							if (!isset($this->data["cookies"][$cookie["domain"]]))  $this->data["cookies"][$cookie["domain"]] = array();
							if (!isset($this->data["cookies"][$cookie["domain"]][$cookie["path"]]))  $this->data["cookies"][$cookie["domain"]][$cookie["path"]] = array();
							$this->data["cookies"][$cookie["domain"]][$cookie["path"]][] = $cookie;
						}
					}
				}

				if ($numfollow > 0)  $numfollow--;
			} while (isset($result["headers"]["Location"]) && $this->data["followlocation"] && $numfollow);

			$result["numredirects"] = $numredirects;
			$result["redirectts"] = $redirectts;

			return $result;
		}

		public function DeleteSessionCookies()
		{
			foreach ($this->data["cookies"] as $domain => $paths)
			{
				foreach ($paths as $path => $cookies)
				{
					foreach ($cookies as $num => $info)
					{
						if (!isset($info["expires_ts"]))  unset($this->data["cookies"][$domain][$path][$num]);
					}

					if (!count($this->data["cookies"][$domain][$path]))  unset($this->data["cookies"][$domain][$path]);
				}

				if (!count($this->data["cookies"][$domain]))  unset($this->data["cookies"][$domain]);
			}
		}

		public function DeleteCookies($domainpattern, $pathpattern, $namepattern)
		{
			foreach ($this->data["cookies"] as $domain => $paths)
			{
				if ($domainpattern == "" || substr($domain, -strlen($domainpattern)) == $domainpattern)
				{
					foreach ($paths as $path => $cookies)
					{
						if ($pathpattern == "" || substr($path, 0, strlen($pathpattern)) == $pathpattern)
						{
							foreach ($cookies as $num => $info)
							{
								if ($namepattern == "" || strpos($info["name"], $namepattern) !== false)  unset($this->data["cookies"][$domain][$path][$num]);
							}

							if (!count($this->data["cookies"][$domain][$path]))  unset($this->data["cookies"][$domain][$path]);
						}
					}

					if (!count($this->data["cookies"][$domain]))  unset($this->data["cookies"][$domain]);
				}
			}
		}

		private function GetExpiresTimestamp($ts)
		{
			$year = (int)substr($ts, 0, 4);
			$month = (int)substr($ts, 5, 2);
			$day = (int)substr($ts, 8, 2);
			$hour = (int)substr($ts, 11, 2);
			$min = (int)substr($ts, 14, 2);
			$sec = (int)substr($ts, 17, 2);

			return gmmktime($hour, $min, $sec, $month, $day, $year);
		}
	}
?>