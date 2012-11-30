<?php if ( $content->error ) : ?>
	<ul class=items">
		<li class="item clear">
			<?php echo $content->error; ?>
		</li>
	</ul>
<?php else : ?>
	<ul class=items">
		<li class="item clear">
			<span class="title pct80"><b>Recent Accuracy</b></span><span class="comments pct20"><?php echo $content->accuracy; ?>%</span>
		</li>
		<li class="item clear">
			<span class="pct80">Spam</span><span class="comments pct20"><?php echo $content->spam; ?></span>
		</li>
		<li class="item clear">
			<span class="pct80">Innocents</span><span class="comments pct20"><?php echo $content->ham; ?></span>
		</li>
		<li class="item clear">
			<span class="pct80">False Negatives</span><span class="comments pct20"><?php echo $content->false_negatives; ?></span>
		</li>
		<li class="item clear">
			<span class="pct80">False Positives</span><span class="comments pct20"><?php echo $content->false_positives; ?></span>
		</li>
	</ul>
<?php endif; ?>
