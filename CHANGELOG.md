### v1.5.2
- Add optional downloads for public shares
- Only ntfy on new media

### v1.5.1
- Updated Tagify 4.27.0 (https://yaireo.github.io/tagify/)
- CSS Fixes
- Move Info Button
- Fix Upload
- Fix path gerneration
- Fix moving images between album
- Fix CheckCounts
- Fix upload issue
- Fix webp path issue
- Add pin Infobox
- Add faster Sharing
- Add OpenGraph Support
- Add share with internal users
- Add option for default share expire
- Add directlink to gallery

### v1.5.0
- Add webp thumbnails
- Add new keywords from maintenance script
- Add Tags in DB
- Add Title, Description and Keywords Editor
- Add Tagify (https://yaireo.github.io/tagify/)
  
### v1.4.21
- Fix CSS
- Fix loading to less photos
- Changed ffmpeg parameters
  
### v1.4.20
- Add to use chunks for scan
- Add button to corrupt images
- Rewrite maintenance
- Fix unescaped pathname for ffprobe
- Fix first image of album
  
### v1.4.19
- Fix wrong naming for videos
- Fix naming scheme
  
### v1.4.18
- Added Download option in album image
- Added Clipboard access for Shares
- Removed some Exif infos from Shares
- Fixed issue in gallery with empty value
- Fixed issue with exif data
  
### v1.4.17
- Added support for ntfy
- Added Rescan of changed media
- Added more EXIF fields
- Added restore point for interrupted maintenance
- Added support of exiftool
- Speedup maintenance
- Fix handling of corrupt video
- Fix action feedback
- Update GLightbox to 3.2.0 (https://github.com/biati-digital/glightbox)
  
### v1.4.16
- Add settings page
- Fix navigation
  
### v1.4.15
- Fix 0 byte thumbnails
- Fix CSS
- Fix Info Button
- Fix lazyload issue
- Add automatic resize folder.jpg
- Add webp support
- Add gallery fullscreen function
- Add plyr 3.7.8 locally (https://github.com/sampotts/plyr)

### v1.4.14
- Fixed create SQL
- Fix dropzone
- Fix encoding
- Fix Upload
- Fix DateTimeDigitized
- Fix create album
- Other minor fixes
- Faster loading times
- Added debug switch
- Added loading animation

### v1.4.13
- Show hint after login instead of email
- Show expire date in share
- Added scroll observer
- Minor fixes

### v1.4.12
- Fix show dummy files
  
### v1.4.11
- Added config option ffmpeg
- Added re-load from within lightbox
- Fix for lightbox
- Fix edit album
- Fix filesize calculation

### v1.4.10
- Fix lazyload multiple times the same
- Fix nullvalue
- Faster performance for dir listing
- Added JustifiedGallery 3.8.1 (https://github.com/miromannino/Justified-Gallery)
- Added GLightbox 3.1.0 (https://github.com/biati-digital/glightbox)

### v1.4.9
- Added lazyload instead of pagination

### v1.4.8
- Added report of broken images to user
- Added delete of orphaned ogv files
- Reworked sorting
- Fixed readEXIF
- Fixed several maintenance issues

### v1.4.7
- Hotfix for deleteing real images on maintenance
- Fix Filetype checking
- Addes more debug Logging

### v1.4.5
- Added creation of dummy files, to prevent re-sync
- PHP8 Fixes
- Fix Thumbnail rotation

### v1.4.4
- Added saving photo information to database
- Added Share feature. You can now share a collection of images with others via links. You don't need an account for the link.
- Added rudimentary hotlink protection
- More PHP8 fixes
- Removed Classic and Larry Skins
  
### v1.4.3
- Fixes for PHP8
  
### v1.4.2
- Remove refresh when plugin is loaded
- Try to move project to Codeberg.org
  
### v1.4.1
- Fix for Roundcube 1.4 RC2
  
### v1.4.0
- Added support for Roundcube 1.4 Elastic Skin

### v1.1.1
- added toolbar button to create new album
- added divider between album / photo buttons
- added toolbar indicator for upload messages (in addition to the websonsole)
- updated lightGallery to version 1.6.10

### v1.1.0
- added a upload function for multiple photos

### v1.0.0
 - added classic skin support
 - removed warning for corrupted jpeg files
 - small change for toolbar button
 - fix for not open folders with folder.jpg inside
 - fix for delete / rename multiple images
 - checkbox for images now only visible by hovering or if they are checked

### v0.9.4
 - fix images not displaying on to the last folder level

### v0.9.3
 - fix for folder.jpg function in subfolders
 - fixed CSS for EXIF infobox
 - display album title in a better way

### v0.9.2
 - fix for CLI maintenance script where a wrong option was mentioned
 - add better identifier for video formats
 - fix for sorting files and folders by config setting value

### v0.9.0
 - Initial version, only available for Larry skin
