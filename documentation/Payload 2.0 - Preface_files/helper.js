


function copyPermalink (fragment)
{
  let url = window.location.toString ();
  let hashPos = url.indexOf ("#");
  
  if (hashPos >= 0)
    url = url.substring (0, hashPos);
  
  if (fragment !== "")
    url = url + "#" + fragment;

  if (navigator.clipboard)
  {
    navigator.clipboard.writeText (url);

  } else
  {
    alert ("failed to copy URL to the clipboard; please copy\n\n" + url);
  }
    
  return false;
}