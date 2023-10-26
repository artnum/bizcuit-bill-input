# BizCuit Bill Input

A daemon listening for new PDF file in a folder in order to split it at 
separator page (see [bizcuit-splitter](https://github.com/artnum/bizcuit-splitter))
and then read Swiss QR Bill content in order to store it into database.

Allows to scan a stack of bill in one go and have them ready for later 
processing.

DB structure should be reworked a bit as it's based on a old existing system.
