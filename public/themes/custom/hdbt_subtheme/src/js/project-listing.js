'use strict';

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.project_listing = {
    attach: function attach(context, settings) {
      // Show only 8 items at first.
      let initialPlanCount = $('.project-teaser').length;

      let numberOfItems = 4;
      let showMoreItems = 4;

      if (initialPlanCount > numberOfItems) {
        $('.project-teaser').hide();
        $('.project-teaser:lt(' + numberOfItems + ')').show();
      }

      // Show more items when clicking the button.
      $('.pager-button').click(function(e) {
        e.preventDefault();

        numberOfItems = numberOfItems + showMoreItems;
        $('.project-teaser:lt(' + numberOfItems + ')').show();

        // Scroll to the bottom of the list.
        $('html').animate({
          scrollTop: $('.project-list__list')[0].scrollHeight
        }, 1000);

        // Hide the button when all items are visible.
        if (numberOfItems >= initialPlanCount) {
          $('.pager-button').hide();
        }
      });
    }
  };
})(jQuery, Drupal, drupalSettings);
