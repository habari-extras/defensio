<?php if ( $block->error ) : ?>
	<ul class=items">
		<li class="item clear">
			<?php echo $block->error; ?>
		</li>
	</ul>
<?php else : ?>
	<ul class=items">
		<li class="item clear">
			<span class="title pct80"><b>Recent Accuracy</b></span><span class="comments pct20"><?php echo $block->accuracy; ?>%</span>
		</li>
		<li class="item clear">
			<span class="pct80">Spam</span><span class="comments pct20"><?php echo $block->spam; ?></span>
		</li>
		<li class="item clear">
			<span class="pct80">Innocents</span><span class="comments pct20"><?php echo $block->ham; ?></span>
		</li>
		<li class="item clear">
			<span class="pct80">False Negatives</span><span class="comments pct20"><?php echo $block->false_negatives; ?></span>
		</li>
		<li class="item clear">
			<span class="pct80">False Positives</span><span class="comments pct20"><?php echo $block->false_positives; ?></span>
		</li>
	</ul>
<?php endif; ?>
