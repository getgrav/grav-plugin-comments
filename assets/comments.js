function escapeRegExp(str) {
    return str.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, "\\$1");
}
jQuery(document).ready(function () {
  var commentForm = $(document).find('.comments-form');
  var commentSection = $(document).find('.comments').first();
  var commentAlert = $(document).find('.alert').first();
  
  //hide form, show link
  commentForm.hide();
  $(document).find('.comment-add-new').show();
  
  //show comment form above comments section (new comment thread)
  $('body').on('click', '.comment-add-new', function (e) {
    e.preventDefault();
    //commentForm.hide(1000);
    $(this).before(commentForm);
    commentForm.show('slow');
    commentAlert.slideUp();
  });
  
  //show comment form below selected comment (reply to existing comment)
  $('body').on('click', '.comment-add-reply', function (e) {
    e.preventDefault();
    var media = $(this).closest('.comment');
    commentForm.hide();
    media.find('>.comment-body>.comment-text').after(commentForm);
    commentForm.show('slow');
    commentAlert.slideUp();
  });
  
  // Attach a submit handler to the form
  $(commentForm).on('submit', function (event) {
    event.preventDefault();
    // Get form data:
    var data = $(this).serialize();
//console.log("Form Data (submit)", JSON.parse(JSON.stringify(data)));
    var url = $(this).attr( "action" );
    //var url = '/nested-comments';
    var parentId = 0;
    var ownLevel = 0;
    if ($(this).parents('.comment').length > 0) {
      parentId = $(this).closest('.comment').attr('data-id');
      ownLevel = parseInt($(this).closest('.comment').attr('data-level'), 10) + 1;
    }
	
    // Send the data using post
    //var posting = $.post(url, { parentId: parentId, data: data }, null, 'json');
    var posting = $.post(url, data + '&parentID=' + parentId, null, 'json');
	
    // Register events to ajax call
    posting.done(function (response) {
//alert('success');
//console.log("Response Data (done)", JSON.parse(JSON.stringify(response)));
      //response = JSON.parse(response); //not needed, post was done using json
      commentForm.after(commentAlert);
      if (!response.status) {
        //should not trigger at all, if all bad requests return the right http status code
        //i.e. <> 200 success => thus triggering posting.fail()
        //leave this check just in case
        commentAlert.stop().css('opacity', 1).text('Error: ' + response.message).fadeIn(30).fadeOut(5000);
        return;   
      }
      if (response.status) {
        commentAlert.css('color', 'green').empty().append(document.createTextNode( response.message )).fadeIn(30);
		var newMedia = "<div class='comment comment-level-{{comment.level|e}} comment-flag-new' data-level='{{comment.level}}' data-id='{{comment.id}}' >" + 
				  "<div class='comment-left'>" +
					"<a href='#'>" +
					  "<img class='comment-object' src='https://www.gravatar.com/avatar/{{comment.email|trim|lower|md5}}?d=identicon' alt='user icon'>" +
					"</a>" +
				  "</div>" +
				  "<div class='comment-body'>" +
					"<div class='comment-heading'>" +
						"<div class='comment-title'><h4>{{comment.title}}</h4></div>" +
						"<div class='comment-reply'><a class='comment-add-reply' href='#'><i class='fa fa-reply' title='{{'PLUGIN_COMMENTS.ADD_REPLY'|t}}'></i> {{'PLUGIN_COMMENTS.ADD_REPLY'|t}}</a></div>" +
						"<div class='comment-meta'>{{'PLUGIN_COMMENTS.WRITTEN_ON'|t}} {{comment.date|e}} {{'PLUGIN_COMMENTS.BY'|t}} {{comment.author}}</div>" +
					"</div>" +
					"<div class='comment-text' >" +
						"{{comment.text}}" +
					"</div>" +
					"{{nested}}" +
				  "</div>" +
				"</div>";
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{comment.id}}"), 'g'), response.data.id);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{comment.level|e}}"), 'g'), ownLevel);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{comment.level}}"), 'g'), ownLevel);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{comment.email|trim|lower|md5}}"), 'g'), response.data.hash);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{parent_id}}"), 'g'), response.data.parent_id);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{comment.title}}"), 'g'), response.data.title);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{comment.text}}"), 'g'), response.data.text);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{comment.author}}"), 'g'), response.data.name);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{comment.date|e}}"), 'g'), response.data.date);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{nested}}"), 'g'), '');
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{'PLUGIN_COMMENTS.ADD_REPLY'|t}}"), 'g'), response.data.ADD_REPLY);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{'PLUGIN_COMMENTS.WRITTEN_ON'|t}}"), 'g'), response.data.WRITTEN_ON);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{'PLUGIN_COMMENTS.BY'|t}}"), 'g'), response.data.BY);
        if ($( "div[data-id='" + response.data.parent_id + "']" ).length > 0) {
			$( "div[data-id='" + response.data.parent_id + "']" ).first().after(newMedia);
        } else {
			$( "div.comments" ).last().prepend(newMedia);
        }
      }
      setTimeout(function () {
        commentForm.hide(2000);
		commentAlert.fadeOut(5000);
      }, 5000);
    });
    posting.fail(function (status, error, title) {
//alert('error');
//console.log("Response Data (fail)", JSON.parse(JSON.stringify(status)));
      commentForm.after(commentAlert);
      commentAlert.empty().append("<p>TEST</p>");
      commentAlert.append("<p>" + status + "</p>");
      commentAlert.append("<p>" + error + "</p>");
      commentAlert.append("<p>" + title + "</p>");
    });
    posting.always(function () {
      //alert("finished, be it successful or not");
    });
  });
});
