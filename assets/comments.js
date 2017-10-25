function escapeRegExp(str) {
    return str.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, "\\$1");
}
jQuery(document).ready(function () {
  var commentForm = $('#comments-form'); //$(document).find('.comments-form').first();
  var commentSection = $('#comments-section'); //$(document).find('.comments').first();
  var commentAlert = $('#comments-alert'); //$(document).find('.alert').first();
  
  //hide form, show link
  commentForm.hide();
  $(document).find('.comment-add-new').show();
  
  //show comment form above comments section (new comment thread)
  $('body').on('click', '.comment-add-new', function (e) {
    e.preventDefault();
	if ($(this).prev().filter('#comments-form').length > 0) {
		//form is already in the right place.
		//just make sure it is visible.
		commentForm.show();
		return;
	}
    commentForm.hide(); //hide it to make sure that it is not shown after move to make "show" transition work.
    $(this).before(commentForm);
    commentForm.show('slow');
    commentAlert.empty().slideUp();
  });
  
  //show comment form below selected comment (reply to existing comment)
  $('body').on('click', '.comment-add-reply', function (e) {
    e.preventDefault();
    var comment = $(this).closest('.comment');
	if (comment.find('#comments-form').length > 0) {
		//form is already in the right place.
		//just make sure it is visible.
		commentForm.show();
		return;
	}
    commentForm.hide();
    comment.find('.comment-body').last().append(commentForm);
    commentForm.show('slow');
    commentAlert.empty().slideUp();
  });
  
  //delete comment (authorized user only)
  $('body').on('click', '.comment-delete', function (e) {
    e.preventDefault();
    var comment = $(this).closest('.comment');
    var id = parseInt(comment.attr('data-id'), 10);
    var level = parseInt(comment.attr('data-level'), 10);
    var nonce = commentForm.find("input[name='form-nonce']").val();
	if (comment.next().filter(".comment[data-level='" + (level + 1) + "']").length > 0) {
		alert('Deletion not allowed. There are replies to this comment. Please delete them first.');
		return;
	}
    var url = commentForm.attr( "action" );
    var posting = $.post(url, { action: 'delete', id: id, nonce: nonce}, null, 'json');
    // Register events to ajax call
    posting.done(function (response) {
    
	//make sure that commentForm is definitely not within the deleted DOM part.
	//hide
	//temporary move it outside the comment selected for deletion. (this definitely exists, not taking any chances here)
	//finally move back to start of commentSection. (preferred target) 
	//Hint: Don't forget commentAlert as it is not inside the form.
    commentAlert.empty().hide();
    commentForm.hide();
	comment.before(commentForm);
	comment.before(commentAlert);
	commentSection.prepend(commentAlert);
	commentSection.prepend(commentForm);
	//remove the comment and all content from DOM.
	//detach would be a soft delete but as there is no reason to reuse the deleted comment, means should not be provided.
	comment.remove();
    });
    posting.fail(function (status, error, title) {
//alert('error');
//console.log("Response Data (fail)", JSON.parse(JSON.stringify(status)));
      commentForm.after(commentAlert);
      commentAlert.show();
      commentAlert.empty().append("<p>Error: </p>");
      commentAlert.append("<p>" + JSON.stringify(status) + "</p>");
      commentAlert.append("<p>" + JSON.stringify(error) + "</p>");
      commentAlert.append("<p>" + JSON.stringify(title) + "</p>");
    });
    posting.always(function () {
      //alert("finished, be it successful or not");
    });
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
					"<img class='comment-object' src='https://www.gravatar.com/avatar/{{comment.email|trim|lower|md5}}?d=identicon' alt='user icon'>" +
				  "</div>" +
				  "<div class='comment-body'>" +
					"<div class='comment-heading'>" +
						"<div class='comment-title'><h4>{{comment.title}}</h4></div>" +
						"<div class='comment-reply'><a class='comment-add-reply' href='#'><i class='fa fa-reply' title='{{'PLUGIN_COMMENTS.ADD_REPLY'|t}}'></i> {{'PLUGIN_COMMENTS.REPLY'|t}}</a></div>" +
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
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{'PLUGIN_COMMENTS.REPLY'|t}}"), 'g'), response.data.REPLY);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{'PLUGIN_COMMENTS.WRITTEN_ON'|t}}"), 'g'), response.data.WRITTEN_ON);
        newMedia = newMedia.replace(new RegExp(escapeRegExp("{{'PLUGIN_COMMENTS.BY'|t}}"), 'g'), response.data.BY);
        if ($( "div[data-id='" + response.data.parent_id + "']" ).length > 0) {
			$( "div[data-id='" + response.data.parent_id + "']" ).first().after(newMedia);
        } else {
			$( "div.comments" ).last().prepend(newMedia);
        }
      }
      setTimeout(function () {
        commentForm.slideUp();
		commentAlert.fadeOut(5000);
      }, 5000);
    });
    posting.fail(function (status, error, title) {
//alert('error');
//console.log("Response Data (fail)", JSON.parse(JSON.stringify(status)));
      commentForm.after(commentAlert);
      commentAlert.show();
      commentAlert.empty().append("<p>Error: </p>");
      commentAlert.append("<p>" + JSON.stringify(status) + "</p>");
      commentAlert.append("<p>" + JSON.stringify(error) + "</p>");
      commentAlert.append("<p>" + JSON.stringify(title) + "</p>");
    });
    posting.always(function () {
      //alert("finished, be it successful or not");
    });
  });
});
