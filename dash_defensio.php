	<ul class=items">
		<li class="item clear">
			<span class="title pct80"><b>Recent Accuracy</b></span><span class="comments pct20"><?php echo $accuracy; ?>%</span>
		</li>
		<li class="item clear">
			<span class="pct80">Spam</span><span class="comments pct20"><?php echo $spam; ?></span>
		</li>
		<li class="item clear">
			<span class="pct80">Innocents</span><span class="comments pct20"><?php echo $ham; ?></span>
		</li>
		<li class="item clear">
			<span class="pct80">False Negatives</span><span class="comments pct20"><?php echo $false_negatives; ?></span>
		</li>
		<li class="item clear">
			<span class="pct80">False Positives</span><span class="comments pct20"><?php echo $false_positives; ?></span>
		</li>
	</ul>
