<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<title><?php echo $title ?></title>
		<link type="text/css" rel="stylesheet" href="<?php echo site_url('css/style.css') ?>" />
	</head>
	<body>

<ul class="navigation clearfix">
	<?php foreach($actions as $label => $url): ?>
	<li><a href="<?php echo $url ?>/"><?php echo $label ?></a></li>
	<?php endforeach ?>
</ul>

<?php echo $content ?>

	</body>
</html>