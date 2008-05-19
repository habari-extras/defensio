<?php include( HABARI_PATH . '/system/admin/header.php'); ?>

<div class="container navigator">
	<span class="older pct10"><a href="#">&laquo; Older</a></span>
	<span class="currentposition pct15 minor">0-0 of 0</span>
	<span class="search pct50"><input type="search" placeholder="Type and wait to search for any entry component" autosave="habaricontent" results="10"></span>
	<span class="nothing pct15">&nbsp;</span>
	<span class="newer pct10"><a href="#">Newer &raquo;</a></span>


</div>

<form method="post" name="moderation" action="<?php URL::out( 'admin', array( 'page' => 'spam' ) ); ?>">
	<input type="hidden" name="search" value="<?php echo $search; ?>">
	<input type="hidden" name="limit" value="<?php echo $limit; ?>">
	<input type="hidden" name="index" value="<?php echo isset($index)?$index:''; ?>">
	<input type="hidden" name="search_status" value="<?php echo $search_status; ?>">
	<input type="hidden" name="nonce" value="<?php echo $wsse['nonce']; ?>">
	<input type="hidden" name="timestamp" value="<?php echo $wsse['timestamp']; ?>">
	<input type="hidden" name="PasswordDigest" value="<?php echo $wsse['digest']; ?>">

	<div class="container transparent">

		<div class="item controls">
			<span class="checkboxandselected pct25">
				<input type="checkbox"></input>
				<span class="selectedtext minor none">None selected</span>
			</span>
			<span class="buttons">
				<input type="submit" name="do_delete" value="Delete" class="deletebutton"></input>
				<input type="submit" name="do_unapprove" value="Unapprove" class="spambutton"></input>
				<input type="submit" name="do_approve" value="Approve" class="approvebutton"></input>
			</span>
		</div>
	</div>

<div id="comments" class="container manage">

<?php usort($comments, array('Defensio', 'sort_by_spaminess')); foreach( $comments as $comment ) : ?>

<div class="item clear" style="<?php echo Defensio::get_spaminess_style($comment); ?>" id="comment_<?php echo $comment->id; ?>">
	<div class="head clear">
		<span class="checkboxandtitle pct25">
			<input type="checkbox" class="checkbox" name="comment_ids[<?php echo $comment->id; ?>]" id="comments_ids[<?php echo $comment->id; ?>]" value="1"></input>
			<?php if($comment->url != ''): ?>
			<a href="#" class="author"><?php echo $comment->name; ?></a>
			<?php else: ?>
			<?php echo $comment->name; ?>
			<?php endif; ?>
		</span>
		<span class="entry pct30"><a href="<?php echo $comment->post->permalink ?>#comment-<?php echo $comment->id; ?>"><?php echo $comment->post->title; ?></a></span>
    <span class="time pct10"><a href="#"><span class="dim">at</span> <?php echo date('H:i', strtotime($comment->date));?></a></span>
    <span class="date pct15"><a href="#"><span class="dim">on</span> <?php echo date('M d, Y', strtotime($comment->date));?></a></span>
		<ul class="dropbutton">
			<li><a href="#" onclick="itemManage.update(<?php echo $comment->id; ?>, 'delete');return false;">Delete</a></li>
			<li><a href="#" onclick="itemManage.update(<?php echo $comment->id; ?>, 'spam');return false;">Spam</a></li>
			<li><a href="#" onclick="itemManage.update(<?php echo $comment->id; ?>, 'approve');return false;">Approve</a></li>
			<li><a href="#" onclick="itemManage.update(<?php echo $comment->id; ?>, 'unapprove');return false;">Unapprove</a></li>
			<li><a href="#">Edit</a></li>
		</ul>
	</div>

	<div class="infoandcontent clear">
		<ul class="spamcheckinfo pct25 minor">
				<?php
				$reasons = (array)$comment->info->spamcheck;
				$reasons = array_unique($reasons);
				foreach($reasons as $reason):
				?>
					<li><?php echo $reason; ?></li>
				<?php endforeach; ?>
		</ul>
		<span class="content pct50"><?php echo Utils::truncate( strip_tags( $comment->content ), 120 ); ?></span>
		<span class="authorinfo pct25 minor">
			<?php if ($comment->url != '')
				echo '<a href="' . $comment->url . '">' . Utils::truncate( $comment->url, 30 ) . '</a>'."\r\n"; ?>
			<?php if ( $comment->email != '' )
				echo '<a href="mailto:' . $comment->email . '">' . Utils::truncate( $comment->email, 30 ) . '</a>'."\r\n"; ?>
		</span>
	</div>
</div>

<?php endforeach; ?>

</div>


<div class="container transparent">

	<div class="item controls">
		<span class="checkboxandselected pct25">
			<input type="checkbox"></input>
			<span class="selectedtext minor none">None selected</span>
		</span>
		<span class="buttons">
			<input type="submit" name="do_delete" value="Delete" class="deletebutton"></input>
			<input type="submit" name="do_unapprove" value="Unapprove" class="spambutton"></input>
			<input type="submit" name="do_approve" value="Approve" class="approvebutton"></input>
		</span>
	</div>
</div>

</form>

<script type="text/javascript">
timelineHandle.loupeUpdate = function(a,b,c) {
	spinner.start();

	$.ajax({
		type: "POST",
		url: "<?php echo URL::get('admin_ajax', array('context' => 'comments')); ?>",
		data: "offset=" + (parseInt(c) - parseInt(b)) + "&limit=" + (parseInt(b) - parseInt(a)) +
			<?php
				$vars= Controller::get_handler_vars();
				$out= '';
				$keys= array_keys($vars);
				foreach($keys as $key) {
					$out .= "&$key=$vars[$key]";
				}
				echo '"' . $out . '"';
			?>,
		dataType: 'json',
		success: function(json){
			$('#comments').html(json.items);
			spinner.stop();
			itemManage.initItems();
			$('.modulecore .item:first-child, ul li:first-child').addClass('first-child').show();
			$('.modulecore .item:last-child, ul li:last-child').addClass('last-child');
		}
	});
};
</script>


<?php include( HABARI_PATH . '/system/admin/footer.php'); ?>