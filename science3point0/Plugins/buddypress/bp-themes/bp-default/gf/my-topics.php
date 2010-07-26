<?php global $bp; if ( gf_has_forum_topics( "type=my-topics&user_id=".$bp->loggedin_user->id ) ) : ?>



	<?php do_action( 'bp_before_directory_forums_list' ) ?>
	<div id="topics-list">
		<h3><?php _e("Topics Started by You","gf");?></h3>
                <ul>
                    
                <?php while ( gf_forum_topics() ) : gf_the_forum_topic(); ?>
			<li class="<?php gf_the_topic_css_class() ?>"> <a class="topic-title" href="<?php gf_the_topic_permalink() ?>" title="<?php gf_the_topic_title() ?> - <?php _e( 'Permalink', 'gf' ) ?>"><?php gf_the_topic_title() ?></a>
                            -<?php printf(__("%s replies","gf"),gf_get_the_topic_total_posts()) ?>

                        -<?php printf(__("Last reply- %s ago","gf"), gf_get_the_topic_time_since_last_post()); ?>
                        <div class="clear"></div>
                        </li>
								<?php endwhile; ?>
			
                </ul>
		<div class="nav">
                     <div class="clear"></div>
			<div id="post-count" class="pag-count">
					<?php gf_forum_pagination_count() ?>
			</div>

			<div class="pagination-links" id="topic-pag">
				<?php gf_forum_pagination() ?>
			</div>

            </div>

	</div><!-- end of discussion -->


	<?php do_action( 'bp_after_directory_forums_list' ) ?>

<?php else: ?>

	<div id="message" class="info">
		<p><?php _e( 'Sorry, there were no forum topics found.', 'gf' ) ?></p>
	</div>

<?php endif;?>