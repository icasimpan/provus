"use strict";(function(a){Drupal.behaviors.sideBarMenu={attach:function attach(b,c){var d=this;a(document).ready(function(){d.init(b,c)})},init:function init(){this.mobileElementPositioning();var b=this;a(window).on("resize",function(){b.mobileElementPositioning()})},mobileElementPositioning:function mobileElementPositioning(){768>a(window).width()?a(".main-sidebar .navbar").each(function(){a(this).prependTo(".pre-content .pre-content__inner")}):a(".pre-content .pre-content__inner .navbar").each(function(){a(this).appendTo(".main-sidebar")})}}})(jQuery);